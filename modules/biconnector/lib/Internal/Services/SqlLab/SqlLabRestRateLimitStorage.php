<?php

namespace Bitrix\BIConnector\Internal\Services\SqlLab;

use Bitrix\BIConnector\Internal\Model\SqlLabRestRateLimitTable;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Type\DateTime;

/**
 * The stored side of the limiter: counters of the frequency window and the lazy removal of rows
 * nobody counts any more. The quotas themselves and the order of the dimensions belong to
 * SqlLabRestLimiter.
 */
class SqlLabRestRateLimitStorage
{
	use MasterConnectionTrait;

	private const CLEANUP_LOCK = 'biconnector_sqllab_cleanup';
	private const CLEANUP_MARKER = '__cleanup__';

	private readonly SqlLabLockManager $lockManager;
	private readonly SqlLabClock $clock;

	public function __construct(?SqlLabLockManager $lockManager = null, ?SqlLabClock $clock = null)
	{
		$this->lockManager = $lockManager ?? new SqlLabLockManager();
		$this->clock = $clock ?? new SqlLabClock();
	}

	/**
	 * @param array[] $dimensions
	 * @return bool|string True when every counter accepted the call, otherwise the type of the
	 *         dimension that refused it.
	 */
	public function consume(array $dimensions, int $windowStart): bool|string
	{
		return $this->withMaster(function (Connection $connection) use ($dimensions, $windowStart): bool|string {
			$connection->startTransaction();
			try
			{
				$keys = array_column($dimensions, 'key');
				$rows = $this->lockRows($connection, $keys);
				$now = DateTime::createFromTimestamp($this->clock->now());
				$windowDate = DateTime::createFromTimestamp($windowStart);
				foreach (array_diff($keys, array_keys($rows)) as $key)
				{
					$addResult = SqlLabRestRateLimitTable::add([
						'DIMENSION_KEY' => $key,
						'WINDOW_START' => $windowDate,
						'REQUEST_COUNT' => 0,
						'UPDATED_AT' => $now,
					]);
					if (!$addResult->isSuccess())
					{
						throw new \RuntimeException('Rate limit storage is unavailable');
					}

					$rows[$key] = [
						'id' => (int)$addResult->getId(),
						'windowStart' => $windowStart,
						'count' => 0,
					];
				}

				$updates = [];
				foreach ($dimensions as $dimension)
				{
					$row = $rows[$dimension['key']] ?? null;
					if ($row === null)
					{
						throw new \RuntimeException('Rate limit storage is unavailable');
					}

					$count = 1;
					if ($row['windowStart'] === $windowStart)
					{
						$count = $row['count'] + 1;
					}
					if ($count > $dimension['frequencyQuota'])
					{
						$connection->rollbackTransaction();

						return $dimension['type'];
					}

					$updates[] = [
						'id' => $row['id'],
						'fields' => [
							'WINDOW_START' => $windowDate,
							'REQUEST_COUNT' => $count,
							'UPDATED_AT' => $now,
						],
					];
				}

				foreach ($updates as $update)
				{
					$updateResult = SqlLabRestRateLimitTable::update($update['id'], $update['fields']);
					if (!$updateResult->isSuccess())
					{
						throw new \RuntimeException('Rate limit storage is unavailable');
					}
				}

				$connection->commitTransaction();

				return true;
			}
			catch (\Throwable $exception)
			{
				$connection->rollbackTransaction();

				throw $exception;
			}
		});
	}

	public function cleanup(int $timestamp, int $interval, int $retention): void
	{
		$this->withMaster(function (Connection $connection) use ($timestamp, $interval, $retention): void {
			// The marker is read before the lock is taken: on all but one call an hour the removal
			// is not due, and such a call must cost a single query instead of a lock round trip.
			if (!$this->isDue($this->readMarker(), $timestamp, $interval))
			{
				return;
			}

			if (!$this->lockManager->acquire(self::CLEANUP_LOCK))
			{
				return;
			}

			try
			{
				$marker = $this->readMarker();
				if (!$this->isDue($marker, $timestamp, $interval))
				{
					return;
				}

				$connection->startTransaction();
				try
				{
					SqlLabRestRateLimitTable::deleteExpired(DateTime::createFromTimestamp($timestamp - $retention));
					$now = DateTime::createFromTimestamp($timestamp);
					if ($marker === false)
					{
						$result = SqlLabRestRateLimitTable::add([
							'DIMENSION_KEY' => self::CLEANUP_MARKER,
							'WINDOW_START' => $now,
							'REQUEST_COUNT' => 0,
							'UPDATED_AT' => $now,
						]);
					}
					else
					{
						$result = SqlLabRestRateLimitTable::update((int)$marker['ID'], [
							'UPDATED_AT' => $now,
						]);
					}
					if (!$result->isSuccess())
					{
						throw new \RuntimeException('Rate limit storage is unavailable');
					}

					$connection->commitTransaction();
				}
				catch (\Throwable $exception)
				{
					$connection->rollbackTransaction();

					throw $exception;
				}
			}
			finally
			{
				try
				{
					$this->lockManager->release(self::CLEANUP_LOCK);
				}
				catch (\Throwable)
				{
				}
			}
		});
	}

	private function readMarker(): array|false
	{
		return SqlLabRestRateLimitTable::getList([
			'select' => ['ID', 'UPDATED_AT'],
			'filter' => ['=DIMENSION_KEY' => self::CLEANUP_MARKER],
			'limit' => 1,
		])->fetch();
	}

	private function isDue(array|false $marker, int $timestamp, int $interval): bool
	{
		return $marker === false || $marker['UPDATED_AT']->getTimestamp() <= $timestamp - $interval;
	}

	/**
	 * One statement is both the read and the lock of the counter rows: the arithmetic needs exactly
	 * the rows the transaction has to hold, so a separate read of the same keys buys nothing.
	 *
	 * @param string[] $keys
	 * @return array<string, array{id: int, windowStart: int, count: int}>
	 */
	private function lockRows(Connection $connection, array $keys): array
	{
		$helper = $connection->getSqlHelper();
		$quotedKeys = array_map(
			static fn(string $key): string => "'" . $helper->forSql($key) . "'",
			$keys,
		);
		$table = $helper->quote(SqlLabRestRateLimitTable::getTableName());
		$keyColumn = $helper->quote('DIMENSION_KEY');
		$columns = implode(', ', [
			$helper->quote('ID'),
			$keyColumn,
			$helper->quote('WINDOW_START'),
			$helper->quote('REQUEST_COUNT'),
		]);
		$rowResult = $connection->query(
			"SELECT {$columns} FROM {$table}"
			. " WHERE {$keyColumn} IN (" . implode(', ', $quotedKeys) . ') FOR UPDATE',
		);

		$rows = [];
		// the driver casts the datetime column by itself, the same way a query of the ORM does
		while ($row = $rowResult->fetch())
		{
			$rows[$row['DIMENSION_KEY']] = [
				'id' => (int)$row['ID'],
				'windowStart' => $row['WINDOW_START']->getTimestamp(),
				'count' => (int)$row['REQUEST_COUNT'],
			];
		}

		return $rows;
	}
}
