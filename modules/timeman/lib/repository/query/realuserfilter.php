<?php

declare(strict_types=1);

namespace Bitrix\Timeman\Repository\Query;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\UserTable;

/**
 * Single query-layer source of truth for telling a real user apart in timeman.
 * A real user is a UserTable row matching the REAL_USER expression (EXTERNAL_AUTH_ID is empty or not a service one);
 * the list of service EXTERNAL_AUTH_ID values belongs to main and is never duplicated here.
 * IS_REAL_USER is not used in conditions: it is a CASE that cannot use the b_user index.
 */
final class RealUserFilter
{
	public static function applyToUserQuery(Query $query): Query
	{
		return $query->where(self::realUserCondition());
	}

	/**
	 * @param array<int, int> $userIds
	 *
	 * @return list<int>
	 */
	public static function filterRealUserIds(array $userIds): array
	{
		return self::selectUserIdsByCondition($userIds, self::realUserCondition());
	}

	/**
	 * Inverse of filterRealUserIds: use it when the caller already holds the whole set
	 * and only needs to know which of it is service, so that the query returns few rows instead of all.
	 *
	 * @param array<int, int> $userIds
	 *
	 * @return list<int>
	 */
	public static function selectServiceUserIds(array $userIds): array
	{
		return self::selectUserIdsByCondition($userIds, self::serviceUserCondition());
	}

	/**
	 * @param array<int, int> $userIds
	 *
	 * @return list<int>
	 */
	private static function selectUserIdsByCondition(array $userIds, ConditionTree $condition): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$rows = UserTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $userIds)
			->where($condition)
			->fetchAll()
		;

		return array_values(
			array_map(static fn (array $row): int => (int)$row['ID'], $rows),
		);
	}

	private static function realUserCondition(): ConditionTree
	{
		return Query::filter()->where('REAL_USER', 'expr', true);
	}

	/**
	 * REAL_USER cannot be negated through its value: 'expr' ignores it, so false builds the same condition as true.
	 */
	private static function serviceUserCondition(): ConditionTree
	{
		return Query::filter()->whereIn('EXTERNAL_AUTH_ID', UserTable::getExternalUserTypes());
	}
}
