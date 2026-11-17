<?php

namespace Bitrix\Main\UI\EntitySelector;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\FinderDestTable;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\ORM\Query\Result;
use Bitrix\Main\Type\DateTime;

final class EntityUsageRetention
{
	public const RETENTION_DAYS_OPTION = 'entity_usage_retention_days';
	public const DEFAULT_RETENTION_DAYS = 180;
	public const PORTION = 1000;
	public const CONTEXT_LIMIT_OPTION = 'entity_usage_context_limit';
	public const DEFAULT_CONTEXT_LIMIT = 200;
	public const MIN_CONTEXT_LIMIT = 100;
	public const TAIL_READ_LIMIT = 10_000;
	/**
	 * The rows a type may hold past the limit before the write path trims it back to the limit: a type left at the limit
	 * by a trim would otherwise be sorted, trimmed by one row and lose the sort cache of its user on every new item.
	 * It is less than the portion, so the write path trims the whole excess at once.
	 */
	public const WRITE_TRIM_SLACK = 100;
	/** The length of b_entity_usage.CONTEXT: MySQL without a strict SQL mode stores a longer context cut to it. */
	private const CONTEXT_LENGTH = 50;

	/**
	 * Returns the number of days to retain entity usage.
	 *
	 * @return int The retention in days, 0 when the rule is switched off.
	 */
	public function getRetentionDays(): int
	{
		$days = (int)Option::get('main', self::RETENTION_DAYS_OPTION, self::DEFAULT_RETENTION_DAYS);

		return max($days, 0);
	}

	/**
	 * Deletes up to a portion of the oldest rows used before the retention cutoff.
	 * The portion is read by the date index and deleted by the primary key, so rows sharing one date
	 * do not make it longer. Rows used again after the read are newer than the cutoff and stay.
	 *
	 * @param int $portion
	 * @return int The number of deleted rows.
	 */
	public function deleteExpiredPortion(int $portion = self::PORTION): int
	{
		$days = $this->getRetentionDays();
		if ($days === 0 || $portion <= 0)
		{
			return 0;
		}

		$cutoff = (new DateTime())->add("-{$days} days");

		// object filters put the server time of a date into the query, whatever the time zone of the current user
		$rows = EntityUsageTable::query()
			->setSelect(['USER_ID', 'CONTEXT', 'ENTITY_ID', 'ITEM_ID'])
			->where('LAST_USE_DATE', '<', $cutoff)
			->setOrder(['LAST_USE_DATE' => 'ASC'])
			->setLimit($portion)
			->exec()
		;

		$groups = [];
		while ($row = $rows->fetch())
		{
			$key = serialize([$row['USER_ID'], $row['CONTEXT'], $row['ENTITY_ID']]);
			$groups[$key] ??= [
				'USER_ID' => (int)$row['USER_ID'],
				'CONTEXT' => $row['CONTEXT'],
				'ENTITY_ID' => $row['ENTITY_ID'],
				'ITEM_ID' => [],
			];
			$groups[$key]['ITEM_ID'][] = $row['ITEM_ID'];
		}

		if (empty($groups))
		{
			return 0;
		}

		$groupsFilter = Query::filter()->logic('or');
		foreach ($groups as $group)
		{
			$groupsFilter->where(
				Query::filter()
					->where('USER_ID', $group['USER_ID'])
					->where('CONTEXT', $group['CONTEXT'])
					->where('ENTITY_ID', $group['ENTITY_ID'])
					->whereIn('ITEM_ID', $group['ITEM_ID']),
			);
		}

		$deleted = $this->deleteRows(
			Query::filter()
				->where('LAST_USE_DATE', '<', $cutoff)
				->where($groupsFilter),
		);

		if ($deleted > 0)
		{
			foreach (array_unique(array_column($groups, 'USER_ID')) as $userId)
			{
				FinderDestTable::cleanSortCache($userId);
			}
		}

		return $deleted;
	}

	/**
	 * Readers read a pair of a user and a context by the entity types of their selector, so the limit applies to every
	 * entity type of the pair on its own: a busy type does not push the rows of a rarer one out of the pair.
	 *
	 * @return int The number of rows kept for each entity type of a pair of a user and a context,
	 * 0 when the rule is switched off.
	 */
	public function getContextLimit(): int
	{
		$limit = (int)Option::get('main', self::CONTEXT_LIMIT_OPTION, self::DEFAULT_CONTEXT_LIMIT);
		if ($limit <= 0)
		{
			return 0;
		}

		return max($limit, self::MIN_CONTEXT_LIMIT);
	}

	/**
	 * Finds the entity types of the pairs of a user and a context holding more rows than the limit among the next users.
	 *
	 * @param int $afterUserId
	 * @param int $userBatch
	 * @return array{pairs: list<array{USER_ID: int, CONTEXT: string, ENTITY_ID: string}>, lastUserId: ?int}
	 * lastUserId is the last user of the batch, null when there are no users past $afterUserId.
	 */
	public function findOverflowingContexts(int $afterUserId, int $userBatch): array
	{
		$userIds = array_map(
			'intval',
			array_column(
					EntityUsageTable::query()
						->setSelect(['USER_ID'])
						->where('USER_ID', '>', $afterUserId)
						->setGroup(['USER_ID'])
						->setOrder(['USER_ID' => 'ASC'])
						->setLimit(max($userBatch, 1))
						->exec()
						->fetchAll(),
				'USER_ID',
			),
		);

		if (empty($userIds))
		{
			return ['pairs' => [], 'lastUserId' => null];
		}

		$result = ['pairs' => [], 'lastUserId' => end($userIds)];

		$limit = $this->getContextLimit();
		if ($limit === 0)
		{
			return $result;
		}

		$rows = EntityUsageTable::query()
			->setSelect(['USER_ID', 'CONTEXT', 'ENTITY_ID'])
			->registerRuntimeField(new ExpressionField('ROWS_COUNT', 'COUNT(*)'))
			->whereIn('USER_ID', $userIds)
			->setGroup(['USER_ID', 'CONTEXT', 'ENTITY_ID'])
			->having('ROWS_COUNT', '>', $limit)
			->setOrder([
				'USER_ID' => 'ASC',
				'CONTEXT' => 'ASC',
				'ENTITY_ID' => 'ASC',
			])
			->exec()
		;
		while ($row = $rows->fetch())
		{
			$result['pairs'][] = [
				'USER_ID' => (int)$row['USER_ID'],
				'CONTEXT' => (string)$row['CONTEXT'],
				'ENTITY_ID' => (string)$row['ENTITY_ID'],
			];
		}

		return $result;
	}

	/**
	 * Trims one entity type of the pair down to the limit by portions of the least recently used rows.
	 * The type has no index by date, so every read of its tail sorts all its rows:
	 * the tail is read once, up to $tailReadLimit rows, and deleted by portions from that read.
	 * Only a tail longer than the read limit is read again.
	 *
	 * @param int $userId
	 * @param string $context
	 * @param string $entityId
	 * @param int $portion
	 * @param int $tailReadLimit
	 * @return \Generator<int, int> Yields the number of rows deleted by each portion.
	 */
	public function trimContext(
		int $userId,
		string $context,
		string $entityId,
		int $portion = self::PORTION,
		int $tailReadLimit = self::TAIL_READ_LIMIT,
	): \Generator
	{
		$limit = $this->getContextLimit();
		if ($limit === 0 || $portion <= 0)
		{
			return;
		}
		$tailReadLimit = max($tailReadLimit, $portion);

		do
		{
			$rows = $this->queryTail($userId, $context, $entityId, $limit, $tailReadLimit);
			$read = 0;
			$deletedByRead = 0;
			$tail = [];
			while ($row = $rows->fetch())
			{
				$read++;
				$tail[] = $row;
				if (count($tail) === $portion)
				{
					$deleted = $this->deleteTail($userId, $context, $entityId, $tail);
					$deletedByRead += $deleted;
					$tail = [];

					yield $deleted;
				}
			}

			if (!empty($tail))
			{
				$deleted = $this->deleteTail($userId, $context, $entityId, $tail);
				$deletedByRead += $deleted;

				yield $deleted;
			}
		}
		// a read that deleted nothing met only refreshed rows, reading again would find them again
		while ($read === $tailReadLimit && $deletedByRead > 0);
	}

	/**
	 * Trims the entity types written just now back to the limit, best effort for the write path: the agent trims the rest.
	 * The checks read the primary key prefix of the type without sorting it. A type within the limit plus the slack costs
	 * one check, a type longer than the limit plus a portion is left to the agent, any other type is trimmed to the limit.
	 *
	 * @param int $userId
	 * @param string $context
	 * @param string[] $entityIds The entity types of the written rows.
	 * @param int $portion
	 * @return int The number of deleted rows.
	 */
	public function trimContextAfterWrite(int $userId, string $context, array $entityIds, int $portion = self::PORTION): int
	{
		// the context as merge wrote it: in upper case, and cut to the column by MySQL
		$context = mb_substr(mb_strtoupper($context), 0, self::CONTEXT_LENGTH);
		$limit = $this->getContextLimit();
		if ($limit === 0 || $userId <= 0 || $context === '' || $portion <= 0)
		{
			return 0;
		}

		try
		{
			$deleted = 0;
			foreach (array_unique(array_map('strtolower', array_map('strval', $entityIds))) as $entityId)
			{
				if (
					$entityId === ''
					|| !$this->hasRowAtPosition($userId, $context, $entityId, $limit + self::WRITE_TRIM_SLACK)
					|| $this->hasRowAtPosition($userId, $context, $entityId, $limit + $portion)
				)
				{
					continue;
				}

				$deleted += $this->deleteTail(
					$userId,
					$context,
					$entityId,
					$this->queryTail($userId, $context, $entityId, $limit, $portion)->fetchAll(),
				);
			}

			return $deleted;
		}
		catch (\Exception $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return 0;
		}
	}

	private function hasRowAtPosition(int $userId, string $context, string $entityId, int $position): bool
	{
		$row = $this->queryTrimmable($userId, $context, $entityId)
			->setSelect(['USER_ID'])
			->setOffset($position)
			->setLimit(1)
			->fetch()
		;

		return (bool)$row;
	}

	/**
	 * The rows of the type past the first $limit ones in the order of the kept rows: the newest first, then by ITEM_ID.
	 */
	private function queryTail(int $userId, string $context, string $entityId, int $limit, int $count): Result
	{
		return $this->queryTrimmable($userId, $context, $entityId)
			->setSelect(['ITEM_ID', 'LAST_USE_DATE'])
			->setOrder([
				'LAST_USE_DATE' => 'DESC',
				'ITEM_ID' => 'ASC',
			])
			->setOffset($limit)
			->setLimit($count)
			->exec()
		;
	}

	/**
	 * The rows of the type that the limit counts and trims.
	 */
	private function queryTrimmable(int $userId, string $context, string $entityId): Query
	{
		return EntityUsageTable::query()
			->where('USER_ID', $userId)
			->where('CONTEXT', $context)
			->where('ENTITY_ID', $entityId)
		;
	}

	/**
	 * Rows used again after the selection are newer than the selected dates and stay.
	 */
	private function deleteTail(int $userId, string $context, string $entityId, array $tail): int
	{
		if (empty($tail))
		{
			return 0;
		}

		$maxDate = null;
		foreach ($tail as $row)
		{
			if ($maxDate === null || $row['LAST_USE_DATE']->getTimestamp() > $maxDate->getTimestamp())
			{
				$maxDate = $row['LAST_USE_DATE'];
			}
		}

		// an array filter would shift the date by the change of the user's time offset since that date
		$deleted = $this->deleteRows(
			Query::filter()
				->where('USER_ID', $userId)
				->where('CONTEXT', $context)
				->where('ENTITY_ID', $entityId)
				->whereIn('ITEM_ID', array_column($tail, 'ITEM_ID'))
				->where('LAST_USE_DATE', '<=', $maxDate),
		);

		if ($deleted > 0)
		{
			FinderDestTable::cleanSortCache($userId);
		}

		return $deleted;
	}

	/**
	 * Deletes the rows of the filter and returns their number, read right after the DELETE.
	 * DataManager::deleteByFilter cleans the ORM cache of the table after the DELETE, and a cache engine
	 * may run a query of its own there (CacheCleanPathTable::add): the number of the connection would be its one.
	 */
	private function deleteRows(ConditionTree $filter): int
	{
		$connection = Application::getConnection();
		$where = Query::buildFilterSql(EntityUsageTable::getEntity(), $filter);
		if ($where === '')
		{
			throw new ArgumentException('Deleting by empty filter is not allowed.', 'filter');
		}

		$connection->queryExecute(
			'DELETE FROM ' . $connection->getSqlHelper()->quote(EntityUsageTable::getTableName()) . ' WHERE ' . $where,
		);
		$deleted = (int)$connection->getAffectedRowsCount();
		EntityUsageTable::cleanCache();

		return $deleted;
	}
}
