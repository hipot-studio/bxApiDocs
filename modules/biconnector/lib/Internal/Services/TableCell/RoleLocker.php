<?php

namespace Bitrix\BIConnector\Internal\Services\TableCell;

use Bitrix\BIConnector\Access\Role\RoleTable;
use Bitrix\Main\Application;

/**
 * Locks the rows of roles till the end of the transaction of the caller, in ascending order of ids.
 *
 * The cells of a role and their links to row rules are read and replaced under the lock of the role, so two saves
 * of one cell are serialized and the last one replaces the set instead of uniting both. One locker lives as long
 * as one transaction: a role locked before is not asked again, so the cells and the links of one save share it.
 */
final class RoleLocker
{
	/**
	 * @var array<int, bool> Whether the role exists, by id of every role looked for under lock.
	 */
	private array $lockedRoles = [];

	/**
	 * @param int[] $roleIds
	 */
	public function lock(array $roleIds): void
	{
		$ids = [];
		foreach ($roleIds as $roleId)
		{
			$roleId = (int)$roleId;
			if ($roleId > 0 && !array_key_exists($roleId, $this->lockedRoles))
			{
				$ids[$roleId] = $roleId;
			}
		}

		if (empty($ids))
		{
			return;
		}

		sort($ids);
		foreach ($ids as $roleId)
		{
			$this->lockedRoles[$roleId] = false;
		}

		$rows = Application::getConnection()->query(
			'SELECT ID FROM ' . RoleTable::getTableName()
			. ' WHERE ID IN (' . implode(', ', $ids) . ') ORDER BY ID FOR UPDATE'
		);
		while ($row = $rows->fetch())
		{
			$this->lockedRoles[(int)$row['ID']] = true;
		}
	}

	/**
	 * Locks the roles that are not locked yet and finds the first one that does not exist.
	 *
	 * @param int[] $roleIds
	 *
	 * @return int|null Id of the first role in ascending order that does not exist.
	 */
	public function lockAndFindMissing(array $roleIds): ?int
	{
		$this->lock($roleIds);

		$ids = array_values(array_unique(array_map('intval', $roleIds)));
		sort($ids);
		foreach ($ids as $roleId)
		{
			if (empty($this->lockedRoles[$roleId]))
			{
				return $roleId;
			}
		}

		return null;
	}
}
