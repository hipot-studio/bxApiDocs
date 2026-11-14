<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository;

use Bitrix\Disk\Internal\Dto\BoardStatistics\BoardCreationBackfillState;
use Bitrix\Disk\Internal\Dto\BoardStatistics\BoardCreationIncrement;
use Bitrix\Disk\Internal\Dto\BoardStatistics\ObjectCreationProbe;
use Bitrix\Disk\Internal\Service\BoardStatistics\ObjectProbeSourceInterface;
use Bitrix\Disk\Internals\BoardCreationBackfillTable;
use Bitrix\Disk\Internals\BoardCreationStatisticTable;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

/**
 * Storage of the board creation statistics: the monthly counters, the single row of the backfill state
 * and the reads of b_disk_object the backfill walks by.
 *
 * Every write is one upsert or update on the given connection, mysql or pgsql, so a caller holding
 * a transaction keeps the writes inside it. The counter upsert adds to the stored value and the state upsert
 * keeps the stored values, which is what makes simultaneous writers safe without a lock of their own.
 * The stored value is named through the table: a bare column in the ON CONFLICT update of pgsql is ambiguous.
 *
 * The state is the row with the given id, the single row of the portal by default; an object without a
 * creation time is invisible to the search of the border and passed by the cursor of a portion.
 */
class BoardCreationStatisticsRepository implements ObjectProbeSourceInterface
{
	private const STATE_COLUMNS = [
		'MAX_OBJECT_ID',
		'MAX_OBJECT_FIXED_AT',
		'START_OBJECT_ID',
		'NEXT_OBJECT_ID',
		'STATUS',
	];
	private const USERS_PER_PORTION = 500;
	/** A null and a stored zero date both fall before this moment: below it there is no creation time. */
	private const EARLIEST_CREATION_TIME = '1970-01-01 00:00:00';

	private readonly Connection $connection;

	public function __construct(
		?Connection $connection = null,
		private readonly int $stateId = BoardCreationBackfillTable::STATE_ID,
	)
	{
		$this->connection = $connection ?? Application::getConnection();
	}

	/**
	 * Creates the backfill state with the border at the greatest object id of the moment, fixed at the
	 * current time of the database, or leaves the existing state untouched, and answers the row that is
	 * stored - never the border computed locally.
	 */
	public function initializeState(): BoardCreationBackfillState
	{
		$helper = $this->connection->getSqlHelper();
		[$sql] = $helper->prepareMerge(
			BoardCreationBackfillTable::getTableName(),
			['ID'],
			[
				'ID' => $this->stateId,
				'MAX_OBJECT_ID' => $this->greatestObjectId(),
				'MAX_OBJECT_FIXED_AT' => $this->expression($helper->getCurrentDateTimeFunction()),
				'START_OBJECT_ID' => null,
				'NEXT_OBJECT_ID' => 0,
				'STATUS' => BoardCreationBackfillTable::STATUS_IN_PROGRESS,
			],
			[
				'MAX_OBJECT_ID' => $this->expression(
					'?#.?#',
					BoardCreationBackfillTable::getTableName(),
					'MAX_OBJECT_ID',
				),
			],
		);
		if ($sql === '')
		{
			throw new SystemException('The merge of the board creation backfill state could not be built.');
		}

		$this->connection->queryExecute($sql);

		return $this->getState() ?? throw new SystemException('The board creation backfill state was not stored.');
	}

	public function getState(): ?BoardCreationBackfillState
	{
		return $this->readState(false);
	}

	/** Reads the state under a row lock; meaningful only inside a transaction of the connection. */
	public function lockState(): ?BoardCreationBackfillState
	{
		return $this->readState(true);
	}

	/** Whether the border of the state was fixed at least the given number of seconds ago by the database clock. */
	public function isBorderFixedAtLeastSecondsAgo(int $seconds): bool
	{
		$helper = $this->connection->getSqlHelper();
		$sql = 'SELECT 1 FROM ' . $helper->quote(BoardCreationBackfillTable::getTableName())
			. ' WHERE ' . $helper->quote('ID') . ' = ' . $this->stateId
			. ' AND ' . $helper->quote('MAX_OBJECT_FIXED_AT') . ' <= ' . $helper->addSecondsToDateTime(-$seconds);

		return (bool)$this->connection->query($sql)->fetch();
	}

	public function saveState(int $nextObjectId, ?int $startObjectId, string $status): void
	{
		$helper = $this->connection->getSqlHelper();
		[$update] = $helper->prepareUpdate(BoardCreationBackfillTable::getTableName(), [
			'NEXT_OBJECT_ID' => $nextObjectId,
			'START_OBJECT_ID' => $startObjectId,
			'STATUS' => $status,
		]);

		$this->connection->queryExecute(
			'UPDATE ' . $helper->quote(BoardCreationBackfillTable::getTableName())
			. ' SET ' . $update
			. ' WHERE ' . $helper->quote('ID') . ' = ' . $this->stateId,
		);
	}

	/** Adds the given number of boards to the counter of the user and the calendar month of the given day. */
	public function increment(int $userId, Date $month, int $count = 1): void
	{
		$this->incrementMany([new BoardCreationIncrement($userId, $month, $count)]);
	}

	/**
	 * @param BoardCreationIncrement[] $increments
	 */
	public function incrementMany(array $increments): void
	{
		$grouped = [];
		foreach ($increments as $increment)
		{
			$month = self::firstDayOfMonth($increment->month);
			$key = $increment->userId . '|' . $month->format('Y-m-d');
			$grouped[$key] ??= ['userId' => $increment->userId, 'month' => $month, 'count' => 0];
			$grouped[$key]['count'] += $increment->count;
		}

		$helper = $this->connection->getSqlHelper();
		foreach ($grouped as $increment)
		{
			[$sql] = $helper->prepareMerge(
				BoardCreationStatisticTable::getTableName(),
				['USER_ID', 'CREATED_MONTH'],
				[
					'USER_ID' => $increment['userId'],
					'CREATED_MONTH' => $increment['month'],
					'BOARD_COUNT' => $increment['count'],
				],
				[
					'BOARD_COUNT' => $this->expression(
						'?#.?# + ?i',
						BoardCreationStatisticTable::getTableName(),
						'BOARD_COUNT',
						$increment['count'],
					),
				],
			);
			if ($sql === '')
			{
				throw new SystemException('The merge of the board creation counter could not be built.');
			}

			$this->connection->queryExecute($sql);
		}
	}

	public function getCount(int $userId, Date $month): int
	{
		$row = BoardCreationStatisticTable::getRow([
			'select' => ['BOARD_COUNT'],
			'filter' => [
				'=USER_ID' => $userId,
				'=CREATED_MONTH' => self::firstDayOfMonth($month),
			],
		]);

		return (int)($row['BOARD_COUNT'] ?? 0);
	}

	/**
	 * Boards created by every given user within the half-open period [from, to) of months.
	 *
	 * @param int[] $userIds
	 * @return array<int, int> user id => number of boards; a user without boards is absent
	 */
	public function sumByUsers(array $userIds, Date $from, Date $to): array
	{
		$userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
		if ($userIds === [])
		{
			return [];
		}

		$sums = [];
		foreach (array_chunk($userIds, self::USERS_PER_PORTION) as $portion)
		{
			$rows = BoardCreationStatisticTable::query()
				->registerRuntimeField(new ExpressionField('TOTAL', 'SUM(%s)', ['BOARD_COUNT']))
				->setSelect(['USER_ID', 'TOTAL'])
				->whereIn('USER_ID', $portion)
				->where('CREATED_MONTH', '>=', self::firstDayOfMonth($from))
				->where('CREATED_MONTH', '<', self::firstDayOfMonth($to))
				->exec()
			;

			while ($row = $rows->fetch())
			{
				$sums[(int)$row['USER_ID']] = (int)$row['TOTAL'];
			}
		}

		return $sums;
	}

	/** The first object within the border is the nearest existing object at or after the smallest id. */
	public function findFirstObject(int $maxObjectId): ?ObjectCreationProbe
	{
		return $this->findObjectAtOrAfter(1, $maxObjectId);
	}

	public function findLastObject(int $maxObjectId): ?ObjectCreationProbe
	{
		$row = self::objectsWithCreationTime()
			->where('ID', '<=', $maxObjectId)
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
			->fetch()
		;

		return $row ? self::probeFromRow($row) : null;
	}

	/** The object with the given id, or the nearest existing one to the right of it, within the border. */
	public function findObjectAtOrAfter(int $objectId, int $maxObjectId): ?ObjectCreationProbe
	{
		if ($objectId > $maxObjectId)
		{
			return null;
		}

		$row = self::objectsWithCreationTime()
			->where('ID', '>=', $objectId)
			->where('ID', '<=', $maxObjectId)
			->setOrder(['ID' => 'ASC'])
			->setLimit(1)
			->fetch()
		;

		return $row ? self::probeFromRow($row) : null;
	}

	/**
	 * Up to the given number of objects by ascending id, starting at the cursor and never beyond the border.
	 * The portion is limited by the number of objects read, not by the number of boards among them.
	 *
	 * @return list<array{ID: int, TYPE: int, TYPE_FILE: ?int, REAL_OBJECT_ID: ?int, CREATED_BY: int, CREATE_TIME: ?DateTime}>
	 */
	public function fetchObjectPortion(int $fromObjectId, int $maxObjectId, int $limit): array
	{
		if ($fromObjectId > $maxObjectId || $limit <= 0)
		{
			return [];
		}

		$rows = ObjectTable::query()
			->setSelect(['ID', 'TYPE', 'TYPE_FILE', 'REAL_OBJECT_ID', 'CREATED_BY', 'CREATE_TIME'])
			->where('ID', '>=', $fromObjectId)
			->where('ID', '<=', $maxObjectId)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->exec()
		;

		$portion = [];
		while ($row = $rows->fetch())
		{
			$portion[] = [
				'ID' => (int)$row['ID'],
				'TYPE' => (int)$row['TYPE'],
				'TYPE_FILE' => $row['TYPE_FILE'] === null ? null : (int)$row['TYPE_FILE'],
				'REAL_OBJECT_ID' => $row['REAL_OBJECT_ID'] === null ? null : (int)$row['REAL_OBJECT_ID'],
				'CREATED_BY' => (int)$row['CREATED_BY'],
				'CREATE_TIME' => $row['CREATE_TIME'] instanceof DateTime ? $row['CREATE_TIME'] : null,
			];
		}

		return $portion;
	}

	private function readState(bool $forUpdate): ?BoardCreationBackfillState
	{
		$helper = $this->connection->getSqlHelper();
		$sql = 'SELECT ' . implode(', ', array_map([$helper, 'quote'], self::STATE_COLUMNS))
			. ' FROM ' . $helper->quote(BoardCreationBackfillTable::getTableName())
			. ' WHERE ' . $helper->quote('ID') . ' = ' . $this->stateId
			. ($forUpdate ? ' FOR UPDATE' : '');

		$row = $this->connection->query($sql)->fetch();
		if (!$row)
		{
			return null;
		}

		return BoardCreationBackfillState::fromRow(array_change_key_case($row, CASE_UPPER));
	}

	/** Protected as a test seam: a test overrides it to run simultaneous initializations with distinct borders. */
	protected function greatestObjectId(): int
	{
		$row = ObjectTable::query()
			->registerRuntimeField(new ExpressionField('MAX_ID', 'MAX(%s)', ['ID']))
			->setSelect(['MAX_ID'])
			->fetch()
		;

		return (int)($row['MAX_ID'] ?? 0);
	}

	/** The id and the creation time of the objects that have one, for the reads of the search. */
	private static function objectsWithCreationTime(): Query
	{
		return ObjectTable::query()
			->setSelect(['ID', 'CREATE_TIME'])
			->where('CREATE_TIME', '>=', new DateTime(self::EARLIEST_CREATION_TIME, 'Y-m-d H:i:s'))
		;
	}

	private static function probeFromRow(array $row): ObjectCreationProbe
	{
		$createTime = $row['CREATE_TIME'];
		if (!$createTime instanceof DateTime)
		{
			throw new SystemException("Object {$row['ID']} carries no creation time.");
		}

		return new ObjectCreationProbe((int)$row['ID'], $createTime);
	}

	/** Compiled by the helper of the connection the statement runs on, not by the one of the default connection. */
	private function expression(string $template, mixed ...$arguments): SqlExpression
	{
		$expression = new SqlExpression($template, ...$arguments);
		$expression->setConnection($this->connection);

		return $expression;
	}

	private static function firstDayOfMonth(Date $day): Date
	{
		return new Date($day->format('Y-m-01'), 'Y-m-d');
	}
}
