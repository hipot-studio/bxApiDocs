<?php

namespace Bitrix\BIConnector\Access\TableGroup;

/**
 * Immutable membership index over the binding rows.
 *
 * The class never touches the database: it is built from the rows already read by the caller.
 * The membership rule lives here and only here, and it is one: a table belongs to the groups it is
 * bound to. The user tables group holds its own binding rows like any other; a code without a
 * single binding belongs nowhere, and the run that arranges the catalog is what keeps that set
 * empty.
 *
 * Table codes are matched exactly: no patterns, prefixes or substrings.
 */
final class TableGroupMembership
{
	/** @var array<string, int[]> */
	private array $groupIdsByTableCode = [];

	/** @var array<int, string[]> */
	private array $tableCodesByGroupId = [];

	/**
	 * @param array<array{GROUP_ID: int|string, TABLE_CODE: string}> $rows Binding rows.
	 */
	public function __construct(array $rows)
	{
		foreach ($rows as $row)
		{
			$groupId = (int)($row['GROUP_ID'] ?? 0);
			$tableCode = (string)($row['TABLE_CODE'] ?? '');

			if ($groupId <= 0 || $tableCode === '')
			{
				continue;
			}

			$this->groupIdsByTableCode[$tableCode][$groupId] = $groupId;
			$this->tableCodesByGroupId[$groupId][$tableCode] = $tableCode;
		}
	}

	/**
	 * Returns the union of all groups the table code is bound to.
	 *
	 * There are no priorities in the union, and an empty one is an honest answer: nobody has been
	 * granted a table that belongs to no group.
	 *
	 * @param string $tableCode
	 *
	 * @return int[]
	 */
	public function getGroupIdsByTableCode(string $tableCode): array
	{
		return array_values($this->groupIdsByTableCode[$tableCode] ?? []);
	}

	/**
	 * Returns the table codes of the group, the user tables group included.
	 *
	 * @param int $groupId
	 *
	 * @return string[]
	 */
	public function getTableCodesByGroupId(int $groupId): array
	{
		return array_values($this->tableCodesByGroupId[$groupId] ?? []);
	}
}
