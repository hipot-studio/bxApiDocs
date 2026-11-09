<?php

namespace Bitrix\BIConnector\Access\Service;

use Bitrix\BIConnector\Access\Permission\PermissionTable;
use Bitrix\BIConnector\Access\Role\RoleUtil;

/**
 * Rights of the roles of one save, written by one deletion and a batch insert for the whole request.
 *
 * Only the pairs "role + right" the payload carries are touched: a pair the request does not name
 * belongs to a section that saves on its own. The unit is the pair and not the row, because a right of
 * the dependent variables type is stored as several rows of one pair, so a partial rewrite would leave
 * halves of two different states.
 */
final class RolePermissionChanges
{
	private const INSERT_CHUNK_SIZE = 1000;

	/** @var array<int, array<string, int|string>> Permission ids by role id. */
	private array $permissionIdsByRole = [];

	private array $rows = [];

	public function add(int $roleId, array $accessRights): void
	{
		foreach ($accessRights as $accessRight)
		{
			$permissionId = $accessRight['id'] ?? null;
			if ($permissionId === null || $permissionId === '')
			{
				continue;
			}

			$this->permissionIdsByRole[$roleId][(string)$permissionId] = $permissionId;

			// The value goes to the storage as it came, a zero included: a row of the empty value is
			// how a group with no operation granted is written down, and dropping it would leave a
			// just created group without a single row of its own.
			$this->rows[] = [
				'ROLE_ID' => $roleId,
				'PERMISSION_ID' => $permissionId,
				'VALUE' => $accessRight['value'] ?? 0,
			];
		}
	}

	/**
	 * @return bool Whether the rows were applied; false means the deletion query failed.
	 */
	public function apply(): bool
	{
		if (!$this->rows)
		{
			return true;
		}

		if (!PermissionTable::deleteList($this->buildDeletionFilter()))
		{
			return false;
		}

		foreach (array_chunk($this->rows, self::INSERT_CHUNK_SIZE) as $rows)
		{
			RoleUtil::insertPermissions($rows);
		}

		return true;
	}

	/**
	 * Roles of the screen usually send the same rights, so the roles are grouped by their set of
	 * rights: the filter has one condition per distinct set and not one per role.
	 */
	private function buildDeletionFilter(): array
	{
		$roleIdsBySet = [];
		$permissionIdsBySet = [];
		foreach ($this->permissionIdsByRole as $roleId => $permissionIds)
		{
			ksort($permissionIds, SORT_STRING);
			$setKey = implode("\n", array_keys($permissionIds));
			$roleIdsBySet[$setKey][] = $roleId;
			$permissionIdsBySet[$setKey] = array_values($permissionIds);
		}

		$filter = ['LOGIC' => 'OR'];
		foreach ($roleIdsBySet as $setKey => $roleIds)
		{
			$filter[] = [
				'@ROLE_ID' => $roleIds,
				'@PERMISSION_ID' => $permissionIdsBySet[$setKey],
			];
		}

		return $filter;
	}
}
