<?php

namespace Bitrix\AI\Limiter;

use Bitrix\AI\Context;
use Bitrix\AI\Limiter\Period\Monthly;
use Bitrix\AI\Limiter\Repository\MonthlyPoolRepository;
use Bitrix\AI\Model\UsageTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

class SharedMonthlyPoolService
{
	public const LOCK_NAME = 'shared_monthly_pool';

	private const LOCK_TIMEOUT = 5;
	private const LOGGER_ID = 'ai.limiter.shared_monthly_pool';

	private readonly Connection $connection;
	private readonly LoggerInterface $logger;

	public function __construct(
		?Connection $connection = null,
		?LoggerInterface $logger = null,
	)
	{
		$this->connection = $connection ?? Application::getConnection();
		$this->logger = $logger ?? (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
	}

	protected function getMode(): string
	{
		try
		{
			return (new SharedMonthlyPoolActivation($this->connection))->getMode();
		}
		catch (Throwable)
		{
			return 'closed';
		}
	}

	protected function getRepository(): MonthlyPoolRepository
	{
		return new MonthlyPoolRepository($this->connection);
	}

	public function reserveSharedMonthly(int $userId, int $cost, int $limit): ?SharedMonthlyReservation
	{
		if ($userId <= 0 || $cost <= 0 || $limit < 0)
		{
			return null;
		}
		try
		{
			$mode = $this->getMode();
			if ($mode === 'legacy')
			{
				return $this->tryConsumeSharedMonthly($userId, $cost, $limit)
					? SharedMonthlyReservation::legacy($userId, $cost) : null;
			}
			if ($mode !== 'aggregate')
			{
				return null;
			}

			return $this->getRepository()->reserve($userId, $this->getCurrentPeriodCode($userId), $cost, $limit);
		}
		catch (Throwable $exception)
		{
			$this->logger->error('Unable to reserve the shared monthly AI pool.', ['exception' => $exception]);

			return null;
		}
	}

	public function findReservation(string $id, int $userId): ?SharedMonthlyReservation
	{
		try
		{
			return $this->getRepository()->findReservation($id, $userId);
		}
		catch (Throwable $exception)
		{
			$this->logger->error('Unable to find the shared monthly AI reservation.', ['exception' => $exception]);

			throw $exception;
		}
	}

	public function persistReservationRefund(string $id, int $userId, \Closure $persist): string
	{
		return $this->getRepository()->persistRefund($id, $userId, $persist);
	}

	public function releaseReservation(SharedMonthlyReservation $reservation): void
	{
		try
		{
			if ($reservation->isTracked())
			{
				// Receipt identity remains authoritative after policy or rollout changes.
				$this->getRepository()->release($reservation);
			}
			elseif ($this->getMode() === 'legacy')
			{
				$this->releaseSharedMonthly($reservation->getUserId(), $reservation->getCost());
			}
		}
		catch (Throwable $exception)
		{
			$this->logger->error('Unable to refund the shared monthly AI pool.', ['exception' => $exception]);
			if ($reservation->isTracked())
			{
				throw $exception;
			}
		}
	}

	public function readTotal(string $period): int
	{
		return match ($this->getMode())
		{
			'aggregate' => $this->getRepository()->readTotal($period),
			'legacy' => $this->getCurrentUsage($period),
			default => throw new \RuntimeException('The shared monthly AI pool is unavailable.'),
		};
	}

	public function canConsumeSharedMonthly(int $userId, int $cost, int $limit): bool
	{
		if ($userId <= 0 || $cost <= 0 || $limit < 0)
		{
			return false;
		}

		$masterOnly = false;

		try
		{
			$this->useMasterOnly(true);
			$masterOnly = true;
			$period = $this->getCurrentPeriodCode($userId);

			return $this->readTotal($period) + $cost <= $limit;
		}
		catch (Throwable $exception)
		{
			$this->logger->error('Unable to check the shared monthly AI pool.', [
				'userId' => $userId,
				'exception' => $exception,
			]);

			return false;
		}
		finally
		{
			if ($masterOnly)
			{
				$this->disableMasterOnly();
			}
		}
	}

	/**
	 * Reserves units in the portal-wide monthly pool.
	 *
	 * @param int $userId User identifier.
	 * @param int $cost Units to reserve.
	 * @param int $limit Portal-wide monthly limit.
	 * @return bool
	 */
	public function tryConsumeSharedMonthly(int $userId, int $cost, int $limit): bool
	{
		if ($userId <= 0 || $cost <= 0 || $limit < 0)
		{
			return false;
		}

		if ($this->getMode() !== 'legacy')
		{
			return false;
		}

		$locked = false;
		$consumed = false;
		$masterOnly = false;

		try
		{
			$this->useMasterOnly(true);
			$masterOnly = true;
			$locked = $this->connection->lock(self::LOCK_NAME, self::LOCK_TIMEOUT);
			if (!$locked)
			{
				$this->logger->warning('Unable to lock the shared monthly AI pool.');

				return false;
			}

			$period = $this->getCurrentPeriodCode($userId);
			$currentUsage = $this->getCurrentUsage($period);
			if ($currentUsage + $cost <= $limit)
			{
				$row = $this->getUserUsage($userId, $period);
				$result = $row === null
					? $this->addUsage($userId, $period, $cost)
					: $this->updateUsage((int)$row['ID'], (int)$row['USAGE_COUNT'] + $cost);

				$consumed = $result->isSuccess();
				if (!$consumed)
				{
					$this->logger->error('Unable to reserve the shared monthly AI pool.', [
						'userId' => $userId,
						'period' => $period,
						'errors' => $result->getErrorMessages(),
					]);
				}
			}
		}
		catch (Throwable $exception)
		{
			$this->logger->error('Unable to reserve the shared monthly AI pool.', [
				'userId' => $userId,
				'exception' => $exception,
			]);
		}
		finally
		{
			if ($locked)
			{
				$this->unlock();
			}
			if ($masterOnly)
			{
				$this->disableMasterOnly();
			}
		}

		return $consumed;
	}

	/**
	 * Releases units previously reserved in the portal-wide monthly pool.
	 *
	 * @param int $userId User identifier.
	 * @param int $cost Units to release.
	 * @return void
	 */
	public function releaseSharedMonthly(int $userId, int $cost): void
	{
		if ($userId <= 0 || $cost <= 0)
		{
			return;
		}

		if ($this->getMode() !== 'legacy')
		{
			return;
		}

		$locked = false;
		$masterOnly = false;

		try
		{
			$this->useMasterOnly(true);
			$masterOnly = true;
			$locked = $this->connection->lock(self::LOCK_NAME, self::LOCK_TIMEOUT);
			if (!$locked)
			{
				$this->logger->warning('Unable to lock the shared monthly AI pool for release.');

				return;
			}

			$period = $this->getCurrentPeriodCode($userId);
			$row = $this->getUserUsage($userId, $period);
			if ($row === null)
			{
				return;
			}

			$result = $this->updateUsage(
				(int)$row['ID'],
				max((int)$row['USAGE_COUNT'] - $cost, 0),
			);
			if (!$result->isSuccess())
			{
				$this->logger->error('Unable to release the shared monthly AI pool.', [
					'userId' => $userId,
					'period' => $period,
					'errors' => $result->getErrorMessages(),
				]);
			}
		}
		catch (Throwable $exception)
		{
			$this->logger->error('Unable to release the shared monthly AI pool.', [
				'userId' => $userId,
				'exception' => $exception,
			]);
		}
		finally
		{
			if ($locked)
			{
				$this->unlock();
			}
			if ($masterOnly)
			{
				$this->disableMasterOnly();
			}
		}
	}

	protected function getCurrentPeriodCode(int $userId): string
	{
		return (new Monthly(new Context('ai', 'shared_monthly_pool', $userId)))->getCode();
	}

	protected function getCurrentUsage(string $period): int
	{
		$row = UsageTable::query()
			->addSelect(Query::expr()->sum('USAGE_COUNT'), 'SUM')
			->where('USAGE_PERIOD', $period)
			->exec()
			->fetch()
		;

		return (int)($row['SUM'] ?? 0);
	}

	/**
	 * @return array{ID: int|string, USAGE_COUNT: int|string}|null
	 */
	protected function getUserUsage(int $userId, string $period): ?array
	{
		$row = UsageTable::query()
			->setSelect(['ID', 'USAGE_COUNT'])
			->where('USER_ID', $userId)
			->where('USAGE_PERIOD', $period)
			->setOrder(['ID' => 'ASC'])
			->setLimit(1)
			->exec()
			->fetch()
		;

		return $row ?: null;
	}

	protected function addUsage(int $userId, string $period, int $cost): AddResult
	{
		return UsageTable::add([
			'USER_ID' => $userId,
			'USAGE_PERIOD' => $period,
			'USAGE_COUNT' => $cost,
		]);
	}

	protected function updateUsage(int $id, int $count): UpdateResult
	{
		return UsageTable::update($id, [
			'USAGE_COUNT' => $count,
			'DATE_MODIFY' => new DateTime(),
		]);
	}

	protected function useMasterOnly(bool $mode): void
	{
		Application::getInstance()->getConnectionPool()->useMasterOnly($mode);
	}

	private function disableMasterOnly(): void
	{
		try
		{
			$this->useMasterOnly(false);
		}
		catch (Throwable $exception)
		{
			$this->logger->error('Unable to disable master-only mode for the shared monthly AI pool.', [
				'exception' => $exception,
			]);
		}
	}

	private function unlock(): bool
	{
		try
		{
			if ($this->connection->unlock(self::LOCK_NAME))
			{
				return true;
			}

			$this->logger->error('Unable to unlock the shared monthly AI pool.');
		}
		catch (Throwable $exception)
		{
			$this->logger->error('Unable to unlock the shared monthly AI pool.', [
				'exception' => $exception,
			]);
		}

		return false;
	}
}
