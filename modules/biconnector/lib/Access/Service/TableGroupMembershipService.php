<?php

namespace Bitrix\BIConnector\Access\Service;

use Bitrix\BIConnector\Access\TableGroup\SystemTableGroupMap;
use Bitrix\BIConnector\Access\TableGroup\TableGroupBindingTable;
use Bitrix\BIConnector\Access\TableGroup\TableGroupMembership;
use Bitrix\BIConnector\Access\TableGroup\TableGroupTable;

/**
 * The only read point of the table group membership.
 *
 * The snapshot is built once per request and kept in a static field; the binding rows behind it
 * are read through the query cache of the data layer. Both layers are dropped by clearCache().
 */
final class TableGroupMembershipService
{
	private const CACHE_TTL = 3600;

	private static ?TableGroupMembership $membership = null;

	private static ?int $userTablesGroupId = null;

	/**
	 * Returns the union of the table codes of the given groups.
	 *
	 * Every group is answered by its binding rows, the user tables one included: the catalog takes no
	 * part in this path at all.
	 *
	 * @param int[] $groupIds
	 *
	 * @return string[]
	 */
	public static function getTableCodesByGroupIds(array $groupIds): array
	{
		$tableCodes = [];
		foreach ($groupIds as $groupId)
		{
			foreach (self::getTableCodesByGroupId((int)$groupId) as $tableCode)
			{
				$tableCodes[$tableCode] = $tableCode;
			}
		}

		return array_values($tableCodes);
	}

	/**
	 * Returns the table codes of the group.
	 *
	 * An unknown or non-positive group id gives an empty list, not an exception: the id comes from
	 * the permission matrix and may outlive the group itself.
	 *
	 * @param int $groupId
	 *
	 * @return string[]
	 */
	public static function getTableCodesByGroupId(int $groupId): array
	{
		if ($groupId <= 0)
		{
			return [];
		}

		return self::getMembership()->getTableCodesByGroupId($groupId);
	}

	/**
	 * Returns the groups the table code belongs to.
	 *
	 * A code without binding rows belongs to no group at all - there is no remainder rule behind
	 * this answer any more, so the empty list means exactly what it says: no row of the permission
	 * matrix grants access to that table. Keeping that set empty is the work of
	 * TableGroupBindingInstaller and of the agent that repeats it.
	 *
	 * @param string $tableCode
	 *
	 * @return int[]
	 */
	public static function getGroupIdsByTableCode(string $tableCode): array
	{
		if ($tableCode === '')
		{
			return [];
		}

		return self::getMembership()->getGroupIdsByTableCode($tableCode);
	}

	/**
	 * @return int|null Id of the user tables group, null while the access subsystem is not installed.
	 */
	public static function getUserTablesGroupId(): ?int
	{
		self::getMembership();

		return self::$userTablesGroupId;
	}

	/**
	 * Drops both cache layers at once: the static snapshot and the query cache of the entities.
	 *
	 * @return void
	 */
	public static function clearCache(): void
	{
		self::$membership = null;
		self::$userTablesGroupId = null;

		TableGroupBindingTable::cleanCache();
		TableGroupTable::cleanCache();
	}

	private static function getMembership(): TableGroupMembership
	{
		if (self::$membership === null)
		{
			self::$membership = self::loadMembership();
		}

		return self::$membership;
	}

	private static function loadMembership(): TableGroupMembership
	{
		$userTablesGroup = TableGroupTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=CODE' => SystemTableGroupMap::getUserTablesGroupCode(),
				'=TYPE' => TableGroupTable::GROUP_TYPE_SYSTEM,
			],
			'limit' => 1,
			'cache' => ['ttl' => self::CACHE_TTL],
		])->fetch();

		self::$userTablesGroupId = $userTablesGroup ? (int)$userTablesGroup['ID'] : null;

		$rows = TableGroupBindingTable::getList([
			'select' => ['GROUP_ID', 'TABLE_CODE'],
			'cache' => ['ttl' => self::CACHE_TTL],
		])->fetchAll();

		return new TableGroupMembership($rows);
	}
}
