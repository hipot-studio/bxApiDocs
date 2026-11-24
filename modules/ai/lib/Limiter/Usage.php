<?php

namespace Bitrix\AI\Limiter;

use Bitrix\AI\Config;
use Bitrix\AI\Context;
use Bitrix\AI\Facade\Bitrix24;
use Bitrix\AI\Limiter\Period\Daily;
use Bitrix\AI\Limiter\Period\Monthly;
use Bitrix\AI\Limiter\Repository\MonthlyPoolRepository;
use Bitrix\AI\Limiter\Policy\LimitPolicyMode;
use Bitrix\AI\Model\UsageTable;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\DeleteResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\Type\DateTime;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

class Usage
{
	protected const DEFAULT_COST = 1;

	private const PERIODS_FOR_MARKET = [
		Period\Daily::class,
	];
	private const LOGGER_ID = 'ai.limiter.usage';

	private LoggerInterface $logger;
	private ?MonthlyPoolRepository $monthlyRepository = null;

	public function __construct(
		private Context $context,
		?LoggerInterface $logger = null,
	)
	{
		$this->logger = $logger ?? (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
	}

	/**
	 * Increments usage count for specific date and time.
	 *
	 * @param int $value Changed count.
	 * @param DateTime|null $time Datetime for period.
	 * @return void
	 */
	public function increment(int $value, ?DateTime $time = null): void
	{
		$this->updateCount(+1 * abs($value), $time);
	}

	/**
	 * Decrements usage count for specific date and time.
	 *
	 * @param int $value Changed count.
	 * @param DateTime|null $time Datetime for period.
	 * @return void
	 */
	public function decrement(int $value, ?DateTime $time = null): void
	{
		$this->updateCount(-1 * abs($value), $time);
	}

	public function getUserId(): int
	{
		return $this->context->getUserId();
	}

	/**
	 * Checks that current request is in limits.
	 *
	 * @param string|null $limitCode Will be returned code of limit.
	 * @param int $cost
	 * @return bool
	 */
	public function isInLimit(?string &$limitCode = null, int $cost = self::DEFAULT_COST): bool
	{
		if (Config::getValue('check_limits') !== 'Y')
		{
			return true;
		}

		foreach ($this->getAvailablePeriods() as $periodInstance)
		{
			$periodInstance = new $periodInstance($this->context);
			if (($periodInstance->getCurrentUsage() + $cost) > $periodInstance->getMaximumUsage())
			{
				$limitCode = (new \ReflectionClass($periodInstance))->getShortName();

				return false;
			}
		}

		return true;
	}

	protected function getMonthlyMode(): string
	{
		return static::getAccountingMode();
	}

	protected function getMonthlyRepository(): MonthlyPoolRepository
	{
		return static::createMonthlyRepository();
	}

	protected static function getAccountingMode(): string
	{
		return (new SharedMonthlyPoolActivation())->getMode();
	}

	protected static function createMonthlyRepository(): MonthlyPoolRepository
	{
		return new MonthlyPoolRepository();
	}

	protected static function getUsageDataClass(): string
	{
		return UsageTable::class;
	}

	/**
	 * Updates usage count for specific date and time.
	 *
	 * @param int $value Changed count.
	 * @param DateTime|null $time Datetime for period.
	 * @return void
	 */

	private function updateCount(int $value, ?DateTime $time = null): void
	{
		if (!$value || $this->getAvailablePeriods() === [])
		{
			return;
		}
		$mode = $this->getMonthlyMode();
		if ($mode === 'closed')
		{
			throw new RuntimeException('The shared monthly AI accounting is unavailable.');
		}
		if ($mode === 'aggregate')
		{
			$repository = $this->getMonthlyRepository();
			$repository->transaction(function () use ($repository, $value, $time) {
				$this->monthlyRepository = $repository;
				try
				{
					$this->updateCountInsideBoundary($value, $time);
				}
				finally
				{
					$this->monthlyRepository = null;
				}
			});

			return;
		}
		$this->updateCountInsideBoundary($value, $time);
	}

	private function updateCountInsideBoundary(int $value, ?DateTime $time = null): void
	{
		if (!$value)
		{
			return;
		}

		$availablePeriods = $this->getAvailablePeriods();
		if ($availablePeriods === [])
		{
			return;
		}

		$periods = $this->getPeriods();

		if ($availablePeriods !== [])
		{
			$useTransaction = count($availablePeriods) > 1 && $this->monthlyRepository === null;
			$transactionStarted = false;
			try
			{
				if ($useTransaction)
				{
					$this->startTransaction();
					$transactionStarted = true;
				}

				// sets usage counts for periods
				foreach ($availablePeriods as $periodInstance)
				{
					$period = (new $periodInstance($this->context))->getCode();

					$isMonthly = $periodInstance === Monthly::class;
					if ($isMonthly && $this->monthlyRepository !== null)
					{
						$this->monthlyRepository->readyTotal($period);
					}

					if (empty($periods[$period]))
					{
						$result = $this->addUsage([
							'USAGE_PERIOD' => $period,
							'USAGE_COUNT' => $value,
							'USER_ID' => $this->context->getUserId(),
						]);
					}
					else
					{
						$result = $this->updateUsage($periods[$period]['ID'], [
							'USAGE_COUNT' => max($periods[$period]['USAGE_COUNT'] + $value, 0),
							'DATE_MODIFY' => $time ?: new DateTime(),
						]);
					}

					if (!$result->isSuccess())
					{
						$this->throwWriteException($period, $result->getErrorMessages());
					}

					if ($isMonthly && $this->monthlyRepository !== null)
					{
						$previous = (int)($periods[$period]['USAGE_COUNT'] ?? 0);
						$next = empty($periods[$period]) ? $value : max($previous + $value, 0);
						$this->monthlyRepository->applyDelta($period, $next - $previous);
					}

					if (!empty($periods[$period]))
					{
						unset($periods[$period]);
					}
				}

				if ($transactionStarted)
				{
					$this->commitTransaction();
					$transactionStarted = false;
				}
			}
			catch (Throwable $exception)
			{
				if ($transactionStarted)
				{
					$this->rollbackTransactionSafely();
				}

				throw $exception;
			}
		}

		$now = new \DateTimeImmutable();
		foreach ($periods as $period)
		{
			$calendarEnd = $this->getCalendarPeriodEnd($period['USAGE_PERIOD']);
			if ($calendarEnd === null || $calendarEnd >= $now)
			{
				continue;
			}

			$isMonthly = preg_match('/^\d{4}-\d{2}$/D', $period['USAGE_PERIOD']) === 1;
			if ($isMonthly && $this->monthlyRepository !== null)
			{
				$this->monthlyRepository->readyTotal($period['USAGE_PERIOD']);
			}
			$result = $this->deleteUsage($period['ID']);
			if ($isMonthly && $this->monthlyRepository !== null)
			{
				if (!$result->isSuccess())
				{
					$this->throwWriteException($period['USAGE_PERIOD'], $result->getErrorMessages());
				}
				$this->monthlyRepository->applyDelta($period['USAGE_PERIOD'], -(int)$period['USAGE_COUNT']);
				$this->monthlyRepository->retireRow((int)$period['ID']);
			}

			if (!$result->isSuccess())
			{
				$this->logger->warning('Unable to delete stale AI usage.', [
					'userId' => $this->context->getUserId(),
					'period' => $period['USAGE_PERIOD'],
					'errors' => $result->getErrorMessages(),
				]);
			}
		}
	}

	/**
	 * Returns available periods.
	 *
	 * @return Period\IPeriod[]
	 */
	protected function getAvailablePeriods(): array
	{
		if (Bitrix24::isNewLimitPolicyActive())
		{
			return match (Bitrix24::getLimitPolicyMode())
			{
				LimitPolicyMode::Unlimited, LimitPolicyMode::MonthlyPool => [],
				LimitPolicyMode::DailyOnly => [Daily::class],
				LimitPolicyMode::Transition => $this->getLegacyAvailablePeriods(),
				LimitPolicyMode::Legacy => $this->getLegacyAvailablePeriods(),
			};
		}

		return $this->getLegacyAvailablePeriods();
	}

	private function getLegacyAvailablePeriods(): array
	{
		if ($this->isMarketAvailable())
		{
			return self::PERIODS_FOR_MARKET;
		}

		$periods = [Daily::class];
		if ($this->hasPlan())
		{
			$periods[] = Monthly::class;
		}

		return $periods;
	}

	private function getCalendarPeriodEnd(string $period): ?\DateTimeImmutable
	{
		$format = match (true)
		{
			preg_match('/^\d{4}-\d{2}-\d{2}$/D', $period) === 1 => '!Y-m-d',
			preg_match('/^\d{4}-\d{2}$/D', $period) === 1 => '!Y-m',
			default => null,
		};
		if ($format === null)
		{
			return null;
		}

		$date = \DateTimeImmutable::createFromFormat($format, $period);
		$errors = \DateTimeImmutable::getLastErrors();
		if (
			$date === false
			|| ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
		)
		{
			return null;
		}

		return $date->modify($format === '!Y-m-d' ? '+1 day' : '+1 month');
	}

	private function throwWriteException(string $period, array $errors): never
	{
		$this->logger->error('Unable to write AI usage.', [
			'userId' => $this->context->getUserId(),
			'period' => $period,
			'errors' => $errors,
		]);

		throw new RuntimeException('Unable to write AI usage.');
	}

	protected function isMarketAvailable(): bool
	{
		return Bitrix24::isMarketAvailable();
	}

	protected function hasPlan(): bool
	{
		return Plan::createByB24() !== null;
	}

	protected function addUsage(array $data): AddResult
	{
		$class = static::getUsageDataClass();

		return $class::add($data);
	}

	protected function updateUsage(int $id, array $data): UpdateResult
	{
		$class = static::getUsageDataClass();

		return $class::update($id, $data);
	}

	protected function deleteUsage(int $id): DeleteResult
	{
		$class = static::getUsageDataClass();

		return $class::delete($id);
	}

	protected function startTransaction(): void
	{
		UsageTable::getEntity()->getConnection()->startTransaction();
	}

	protected function commitTransaction(): void
	{
		UsageTable::getEntity()->getConnection()->commitTransaction();
	}

	protected function rollbackTransaction(): void
	{
		UsageTable::getEntity()->getConnection()->rollbackTransaction();
	}

	private function rollbackTransactionSafely(): void
	{
		try
		{
			$this->rollbackTransaction();
		}
		catch (Throwable $exception)
		{
			$this->logger->error('Unable to roll back AI usage transaction.', [
				'userId' => $this->context->getUserId(),
				'exception' => $exception,
			]);
		}
	}

	/**
	 * Returns all periods for current Context.
	 *
	 * @return array
	 */
	protected function getPeriods(): array
	{
		$periods = [];

		$class = static::getUsageDataClass();
		$res = $class::query()
			->setSelect(['ID', 'USAGE_PERIOD', 'USAGE_COUNT'])
			->where('USER_ID', $this->context->getUserId())
			->exec()
		;
		while ($row = $res->fetch())
		{
			$periods[$row['USAGE_PERIOD']] = $row;
		}

		return $periods;
	}

	/**
	 * Finally removes all records for the user.
	 *
	 * @param int $userId User id.
	 * @return void
	 */
	public static function deleteForUser(int $userId): void
	{
		$filter = ['=USER_ID' => $userId];
		if (Bitrix24::getLimitPolicyMode() === LimitPolicyMode::MonthlyPool)
		{
			$currentMonth = (new Monthly(new Context('ai', 'shared_monthly_pool', $userId)))->getCode();
			$filter['!=USAGE_PERIOD'] = $currentMonth;
		}

		$mode = static::getAccountingMode();
		$class = static::getUsageDataClass();
		if ($mode === 'legacy')
		{
			$class::deleteByFilter($filter);

			return;
		}
		if ($mode !== 'aggregate')
		{
			throw new RuntimeException('The shared monthly AI accounting is unavailable.');
		}
		$repository = static::createMonthlyRepository();
		$repository->transaction(static function () use ($repository, $filter, $class) {
			$rows = $class::getList(['filter' => $filter]);
			while ($row = $rows->fetch())
			{
				$isMonthly = preg_match('/^\d{4}-\d{2}$/D', $row['USAGE_PERIOD']) === 1;
				if ($isMonthly)
				{
					$repository->readyTotal($row['USAGE_PERIOD']);
				}
				if (!$class::delete((int)$row['ID'])->isSuccess())
				{
					throw new RuntimeException('Unable to delete AI usage.');
				}
				if ($isMonthly)
				{
					$repository->applyDelta($row['USAGE_PERIOD'], -(int)$row['USAGE_COUNT']);
					$repository->retireRow((int)$row['ID']);
				}
			}
		});
	}
}
