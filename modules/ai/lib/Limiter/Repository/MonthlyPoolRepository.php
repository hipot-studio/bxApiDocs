<?php

namespace Bitrix\AI\Limiter\Repository;

use Bitrix\AI\Limiter\SharedMonthlyPoolService;
use Bitrix\AI\Limiter\SharedMonthlyReservation;
use Bitrix\AI\Model\MonthlyPoolReceiptTable;
use Bitrix\AI\Model\MonthlyPoolTotalTable;
use Bitrix\AI\Model\UsageTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UuidGenerator;

class MonthlyPoolRepository
{
	private Connection $connection;
	private bool $insideTransaction = false;

	public function __construct(
		?Connection $connection = null,
		private readonly string $usageClass = UsageTable::class,
		private readonly string $totalClass = MonthlyPoolTotalTable::class,
		private readonly string $receiptClass = MonthlyPoolReceiptTable::class,
	)
	{
		$this->connection = $connection ?? Application::getConnection();
	}

	public function transaction(callable $callback): mixed
	{
		if ($this->insideTransaction)
		{
			throw new \LogicException('Nested monthly pool boundaries are forbidden.');
		}
		$locked = false;
		$started = false;
		try
		{
			$this->useMasterOnly(true);
			$locked = $this->connection->lock(SharedMonthlyPoolService::LOCK_NAME, 5);
			if (!$locked)
			{
				throw new \RuntimeException('Unable to lock the shared monthly AI pool.');
			}
			$this->connection->startTransaction();
			$started = true;
			$this->insideTransaction = true;
			$result = $callback($this);
			$this->connection->commitTransaction();
			$started = false;

			return $result;
		}
		finally
		{
			$this->insideTransaction = false;
			try
			{
				if ($started)
				{
					$this->connection->rollbackTransaction();
				}
			}
			finally
			{
				try
				{
					if ($locked)
					{
						$this->unlockSafely();
					}
				}
				finally
				{
					try
					{
						$this->useMasterOnly(false);
					}
					catch (\Throwable)
					{
						$this->logCleanupFailure('Unable to restore monthly pool master-only routing.');
					}
				}
			}
		}
	}

	public function readTotal(string $period): int
	{
		return $this->transaction(fn(): int => $this->readyTotal($period));
	}

	public function readyTotal(string $period): int
	{
		$this->assertTransaction();
		$class = $this->totalClass;
		$row = $class::getByPrimary($period)->fetch();
		if ($row && $row['READY'] === 'Y')
		{
			return (int)$row['TOTAL'];
		}
		$total = $this->sumUsage($period);
		$this->check($row
			? $class::update($period, ['TOTAL' => $total, 'READY' => 'Y'])
			: $class::add(['PERIOD' => $period, 'TOTAL' => $total, 'READY' => 'Y'])
		);

		return $total;
	}

	public function applyDelta(string $period, int $delta): void
	{
		$total = $this->readyTotal($period);
		$class = $this->totalClass;
		$this->check($class::update($period, ['TOTAL' => $total + $delta]));
	}

	public function reserve(int $userId, string $period, int $cost, int $limit): ?SharedMonthlyReservation
	{
		return $this->transaction(function () use ($userId, $period, $cost, $limit) {
			$total = $this->readyTotal($period);
			if ($total + $cost > $limit)
			{
				return null;
			}
			$class = $this->usageClass;
			$row = $class::query()->setSelect(['ID', 'USAGE_COUNT'])
				->where('USER_ID', $userId)->where('USAGE_PERIOD', $period)
				->setOrder(['ID' => 'ASC'])->setLimit(1)->exec()->fetch();
			if ($row)
			{
				$id = (int)$row['ID'];
				$this->check($class::update($id, [
					'USAGE_COUNT' => (int)$row['USAGE_COUNT'] + $cost, 'DATE_MODIFY' => new DateTime(),
				]));
			}
			else
			{
				$result = $class::add(['USER_ID' => $userId, 'USAGE_PERIOD' => $period, 'USAGE_COUNT' => $cost]);
				$this->check($result);
				$id = (int)$result->getId();
			}
			$class = $this->totalClass;
			$this->check($class::update($period, ['TOTAL' => $total + $cost]));
			$receiptId = UuidGenerator::generateV4();
			$this->insertReceipt([
				'ID' => $receiptId, 'USER_ID' => $userId, 'USAGE_ROW_ID' => $id,
				'PERIOD' => $period, 'COST' => $cost, 'STATE' => 'charged',
			]);

			return SharedMonthlyReservation::tracked($receiptId, $userId, $period, $cost);
		});
	}

	public function findReservation(string $id, int $userId): ?SharedMonthlyReservation
	{
		if ($userId <= 0 || !preg_match(
			'/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $id,
		))
		{
			return null;
		}
		$find = function () use ($id, $userId) {
			$class = $this->receiptClass;
			$row = $class::getByPrimary($id)->fetch();
			if (!$row || (int)$row['USER_ID'] !== $userId)
			{
				return null;
			}

			return SharedMonthlyReservation::tracked($row['ID'], (int)$row['USER_ID'], $row['PERIOD'], (int)$row['COST']);
		};

		return $this->insideTransaction ? $find() : $this->transaction($find);
	}

	public function persistRefund(string $id, int $userId, callable $persist): string
	{
		if ($userId <= 0 || !preg_match(
			'/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $id,
		))
		{
			throw new \InvalidArgumentException('Invalid deferred monthly receipt identity.');
		}

		$started = false;
		try
		{
			$this->useMasterOnly(true);
			$this->connection->startTransaction();
			$started = true;
			$class = $this->receiptClass;
			$update = new SqlExpression(
				'UPDATE ?# SET ?# = ?s WHERE ?# = ?s AND ?# = ?i AND (?# = ?s OR ?# = ?s)',
				$class::getTableName(),
				'STATE',
				'charged',
				'ID',
				$id,
				'USER_ID',
				$userId,
				'STATE',
				'charged',
				'STATE',
				'refund_pending',
			);
			$update->setConnection($this->connection);
			$this->connection->queryExecute($update->compile());
			$row = $class::getByPrimary($id)->fetch();
			if (!$row || (int)$row['USER_ID'] !== $userId || $row['STATE'] !== 'charged')
			{
				throw new \InvalidArgumentException('The deferred monthly reservation receipt is unavailable.');
			}
			$result = $persist();
			$this->connection->commitTransaction();
			$started = false;

			return $result;
		}
		finally
		{
			try
			{
				if ($started)
				{
					$this->connection->rollbackTransaction();
				}
			}
			finally
			{
				$this->useMasterOnly(false);
			}
		}
	}

	public function release(SharedMonthlyReservation $reservation): void
	{
		if (!$reservation->isTracked())
		{
			return;
		}
		$this->transaction(function () use ($reservation) {
			$class = $this->receiptClass;
			$receipt = $class::getByPrimary($reservation->getId())->fetch();
			if (!$receipt || !in_array($receipt['STATE'], ['charged', 'refund_pending'], true))
			{
				return;
			}
			$usageClass = $this->usageClass;
			$row = $usageClass::getByPrimary((int)$receipt['USAGE_ROW_ID'])->fetch();
			if (!$row || (int)$row['USER_ID'] !== (int)$receipt['USER_ID']
				|| $row['USAGE_PERIOD'] !== $receipt['PERIOD'])
			{
				throw new \RuntimeException('The charged monthly usage row is missing.');
			}
			$this->readyTotal($receipt['PERIOD']);
			$count = max(0, (int)$row['USAGE_COUNT'] - (int)$receipt['COST']);
			$this->check($usageClass::update((int)$row['ID'], [
				'USAGE_COUNT' => $count, 'DATE_MODIFY' => new DateTime(),
			]));
			$this->applyDelta($receipt['PERIOD'], $count - (int)$row['USAGE_COUNT']);
			$this->check($class::update($receipt['ID'], ['STATE' => 'refunded']));
		});
	}

	public function retireRow(int $id): void
	{
		$this->assertTransaction();
		$class = $this->receiptClass;
		$connection = $class::getEntity()->getConnection();
		$update = new SqlExpression(
			'UPDATE ?# SET ?# = ?s WHERE ?# = ?i AND (?# = ?s OR ?# = ?s)',
			$class::getTableName(),
			'STATE',
			'retired',
			'USAGE_ROW_ID',
			$id,
			'STATE',
			'charged',
			'STATE',
			'refund_pending',
		);
		$update->setConnection($connection);
		$connection->queryExecute($update->compile());
	}

	public function cleanupReceiptsBefore(
		string $cutoffPeriod,
		int $limit = 100,
	): int
	{
		if (!preg_match('/\A\d{4}-(?:0[1-9]|1[0-2])\z/', $cutoffPeriod) || $limit <= 0)
		{
			throw new \InvalidArgumentException('A valid receipt cutoff period and positive limit are required.');
		}

		return $this->transaction(function () use ($cutoffPeriod, $limit): int {
			$class = $this->receiptClass;
			$ids = [];
			$limit = min($limit, 100);
			foreach (['refunded', 'retired'] as $state)
			{
				$rows = $class::query()
					->setSelect(['ID'])
					->where('STATE', $state)
					->where('PERIOD', '<', $cutoffPeriod)
					->setOrder(['PERIOD' => 'ASC', 'ID' => 'ASC'])
					->setLimit($limit - count($ids))
					->fetchAll()
				;
				array_push($ids, ...array_column($rows, 'ID'));
				if (count($ids) === $limit)
				{
					break;
				}
			}
			if ($ids === [])
			{
				return 0;
			}

			$class::deleteByFilter([
				'@ID' => $ids,
				'@STATE' => ['refunded', 'retired'],
			]);

			return count($ids);
		});
	}

	protected function insertReceipt(array $data): void
	{
		$class = $this->receiptClass;
		$this->check($class::add($data));
	}

	protected function sumUsage(string $period): int
	{
		$class = $this->usageClass;
		$row = $class::query()->addSelect(Query::expr()->sum('USAGE_COUNT'), 'SUM')
			->where('USAGE_PERIOD', $period)->exec()->fetch();

		return (int)($row['SUM'] ?? 0);
	}

	protected function useMasterOnly(bool $mode): void
	{
		Application::getInstance()->getConnectionPool()->useMasterOnly($mode);
	}

	private function unlockSafely(): void
	{
		try
		{
			if ($this->connection->unlock(SharedMonthlyPoolService::LOCK_NAME))
			{
				return;
			}
		}
		catch (\Throwable)
		{
		}
		$this->logCleanupFailure('Unable to unlock the shared monthly AI pool.');
	}

	private function logCleanupFailure(string $message): void
	{
		try
		{
			$logger = (new \Bitrix\Main\Diag\LoggerFactory())->createById('ai.limiter.shared_monthly_pool');
			$logger?->error($message);
		}
		catch (\Throwable)
		{
		}
	}

	private function check(\Bitrix\Main\Result $result): void
	{
		if (!$result->isSuccess())
		{
			throw new \RuntimeException('Unable to persist shared monthly AI accounting.');
		}
	}

	private function assertTransaction(): void
	{
		if (!$this->insideTransaction)
		{
			throw new \LogicException('Monthly pool writes require the shared transaction boundary.');
		}
	}
}
