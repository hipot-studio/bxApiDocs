<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository\Sync;

use Bitrix\Disk\Internal\Model\SyncMetricTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Type\DateTime;
use Throwable;

/**
 * Aggregates of the metrics of the sync contour.
 *
 * Every write is one upsert that adds up in the database, so concurrent calls of one key never overwrite
 * each other. Windows are hours by the clock of the database, the one the journal is stamped by.
 */
final class SyncMetricRepository
{
	public const HOURLY_RETENTION_DAYS = 35;

	private const NAME_LOSS = 'loss';
	private const NAME_GAUGE = 'gauge';

	private const PRIMARY = ['WINDOW_START', 'NAME', 'LABEL', 'APP_HASH', 'OUTCOME', 'BUCKET_MS'];

	private readonly Connection $connection;

	public function __construct(?Connection $connection = null)
	{
		$this->connection = $connection ?? Application::getConnection();
	}

	/**
	 * @throws SqlQueryException when the upsert fails, or deadlocks twice in a row.
	 */
	public function increment(
		string $name,
		string $label,
		string $appId,
		string $outcome,
		int $bucketMs,
		DateTime $window,
		int $count = 1,
		int $sumMs = 0,
		int $sumBytes = 0,
	): void
	{
		$table = SyncMetricTable::getTableName();

		$this->upsert(
			[
				'WINDOW_START' => $window,
				'NAME' => $name,
				'LABEL' => $label,
				'APP_HASH' => md5($appId),
				'OUTCOME' => $outcome,
				'BUCKET_MS' => $bucketMs,
				'APP_ID' => $appId,
				'CNT' => $count,
				'SUM_MS' => $sumMs,
				'SUM_BYTES' => $sumBytes,
			],
			[
				// Qualified by the table: PostgreSQL calls a bare column in ON CONFLICT DO UPDATE ambiguous.
				'CNT' => new SqlExpression('?#.?# + ?i', $table, 'CNT', $count),
				'SUM_MS' => new SqlExpression('?#.?# + ?i', $table, 'SUM_MS', $sumMs),
				'SUM_BYTES' => new SqlExpression('?#.?# + ?i', $table, 'SUM_BYTES', $sumBytes),
			],
		);
	}

	/**
	 * The loss lands in the same hot row that may have just failed, so the counter is a lower bound.
	 */
	public function recordLoss(string $label, DateTime $window): void
	{
		try
		{
			$this->increment(self::NAME_LOSS, $label, '', '', 0, $window);
		}
		catch (Throwable)
		{
		}
	}

	/**
	 * Writes the rows by one statement in the current hour by the clock of the database. The hour is an
	 * expression of the statement, constant within it, so all the rows land in one hour even at its edge.
	 *
	 * @param list<array{name: string, label: string, appId: string, outcome: string, bucketMs: int,
	 *     count?: int, sumMs?: int, sumBytes?: int}> $rows
	 * @throws SqlQueryException when the upsert fails, or deadlocks twice in a row.
	 */
	public function incrementInCurrentHour(array $rows): void
	{
		if (empty($rows))
		{
			return;
		}

		$table = SyncMetricTable::getTableName();
		$window = $this->currentHourExpression();
		$insertRows = [];
		foreach ($this->mergeRowsOfOneKey($rows) as $row)
		{
			$insertRows[] = [
				'WINDOW_START' => $window,
				'NAME' => $row['name'],
				'LABEL' => $row['label'],
				'APP_HASH' => $row['appHash'],
				'OUTCOME' => $row['outcome'],
				'BUCKET_MS' => $row['bucketMs'],
				'APP_ID' => $row['appId'],
				'CNT' => $row['count'],
				'SUM_MS' => $row['sumMs'],
				'SUM_BYTES' => $row['sumBytes'],
			];
		}

		$this->execute(
			$this->connection->getSqlHelper()->prepareMergeValues(
				$table,
				self::PRIMARY,
				$insertRows,
				[
					// Qualified by the table: PostgreSQL calls a bare column in ON CONFLICT DO UPDATE ambiguous.
					'CNT' => new SqlExpression('?#.?# + ?v', $table, 'CNT', 'CNT'),
					'SUM_MS' => new SqlExpression('?#.?# + ?v', $table, 'SUM_MS', 'SUM_MS'),
					'SUM_BYTES' => new SqlExpression('?#.?# + ?v', $table, 'SUM_BYTES', 'SUM_BYTES'),
				],
			)
		);
	}

	/**
	 * The loss of the current hour by the clock of the database; best effort, as recordLoss().
	 */
	public function recordLossInCurrentHour(string $label): void
	{
		try
		{
			$this->incrementInCurrentHour([
				[
					'name' => self::NAME_LOSS,
					'label' => $label,
					'appId' => '',
					'outcome' => '',
					'bucketMs' => 0,
				],
			]);
		}
		catch (Throwable)
		{
		}
	}

	/**
	 * @throws SqlQueryException
	 */
	public function setGauge(string $label, int $value, DateTime $window): void
	{
		$this->upsert(
			[
				'WINDOW_START' => $window,
				'NAME' => self::NAME_GAUGE,
				'LABEL' => $label,
				'APP_HASH' => md5(''),
				'OUTCOME' => '',
				'BUCKET_MS' => 0,
				'APP_ID' => '',
				'VALUE' => $value,
			],
			[
				'VALUE' => $value,
			],
		);
	}

	/**
	 * @return int Number of rows removed.
	 * @throws SqlQueryException
	 */
	public function deleteHourlyBefore(DateTime $before, int $limit): int
	{
		$helper = $this->connection->getSqlHelper();
		$windowStart = $helper->quote('WINDOW_START');

		$this->connection->queryExecute(
			$helper->prepareDeleteLimit(
				SyncMetricTable::getTableName(),
				self::PRIMARY,
				$windowStart . ' < ' . $helper->convertToDbDateTime($before)
				. ' AND ' . $windowStart . ' <> ' . $helper->convertToDbDateTime($this->totalWindow()),
				['WINDOW_START' => 'ASC'],
				$limit,
			)
		);

		return $this->connection->getAffectedRowsCount();
	}

	/**
	 * The current hour by the clock of the database.
	 */
	public function hourOf(): DateTime
	{
		$row = $this->connection->query(
			'SELECT ' . $this->connection->getSqlHelper()->getCurrentDateTimeFunction() . ' AS NOW_AT'
		)->fetch();

		$now = $row['NOW_AT'] instanceof DateTime
			? $row['NOW_AT']
			: new DateTime((string)$row['NOW_AT'], 'Y-m-d H:i:s');

		return new DateTime($now->format('Y-m-d H:00:00'), 'Y-m-d H:i:s');
	}

	public function totalWindow(): DateTime
	{
		return new DateTime(SyncMetricTable::TOTAL_WINDOW, 'Y-m-d H:i:s');
	}

	/**
	 * @throws SqlQueryException
	 */
	private function upsert(array $insertFields, array $updateFields): void
	{
		[$sql] = $this->connection->getSqlHelper()->prepareMerge(
			SyncMetricTable::getTableName(),
			self::PRIMARY,
			$insertFields,
			$updateFields,
		);

		$this->execute($sql);
	}

	/**
	 * @throws SqlQueryException
	 */
	private function execute(string $sql): void
	{
		try
		{
			$this->connection->queryExecute($sql);
		}
		catch (SqlQueryException $error)
		{
			if (!$this->isDeadlock($error))
			{
				throw $error;
			}

			$this->connection->queryExecute($sql);
		}
	}

	/**
	 * Rows of one key are added up: PostgreSQL cannot update one row twice by one ON CONFLICT statement.
	 * The rows go in the order of the key, so concurrent calls lock the rows in one order.
	 *
	 * @return list<array{name: string, label: string, appId: string, outcome: string, bucketMs: int,
	 *     appHash: string, count: int, sumMs: int, sumBytes: int}>
	 */
	private function mergeRowsOfOneKey(array $rows): array
	{
		$merged = [];
		foreach ($rows as $row)
		{
			$appId = (string)$row['appId'];
			$key = implode("\0", [$row['name'], $row['label'], $appId, $row['outcome'], (int)$row['bucketMs']]);
			if (!isset($merged[$key]))
			{
				$merged[$key] = [
					'name' => (string)$row['name'],
					'label' => (string)$row['label'],
					'appId' => $appId,
					'appHash' => md5($appId),
					'outcome' => (string)$row['outcome'],
					'bucketMs' => (int)$row['bucketMs'],
					'count' => 0,
					'sumMs' => 0,
					'sumBytes' => 0,
				];
			}

			$merged[$key]['count'] += (int)($row['count'] ?? 1);
			$merged[$key]['sumMs'] += (int)($row['sumMs'] ?? 0);
			$merged[$key]['sumBytes'] += (int)($row['sumBytes'] ?? 0);
		}

		$merged = array_values($merged);
		usort(
			$merged,
			static fn (array $a, array $b): int => strcmp($a['name'], $b['name'])
				?: strcmp($a['label'], $b['label'])
				?: strcmp($a['appHash'], $b['appHash'])
				?: strcmp($a['outcome'], $b['outcome'])
				?: $a['bucketMs'] <=> $b['bucketMs']
		);

		return $merged;
	}

	/**
	 * The current hour by the clock of the database, as hourOf() reads it. The clock is read once per
	 * statement (MySQL) or transaction (PostgreSQL); SYSDATE() and clock_timestamp() would move within it.
	 */
	private function currentHourExpression(): SqlExpression
	{
		$now = $this->connection->getSqlHelper()->getCurrentDateTimeFunction();

		return new SqlExpression(
			$this->connection->getType() === 'pgsql'
				? "date_trunc('hour', " . $now . ')'
				: 'DATE_FORMAT(' . $now . ", '%Y-%m-%d %H:00:00')"
		);
	}

	private function isDeadlock(SqlQueryException $error): bool
	{
		return stripos((string)$error->getDatabaseMessage(), 'deadlock') !== false;
	}
}
