<?php

namespace Bitrix\BIConnector\Public\Provider\TableCell;

use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Access\Permission\PermissionTable;
use Bitrix\BIConnector\Public\Command\TableCell\TableCellChange;
use Bitrix\BIConnector\Public\Command\TableCell\TableRights;

/**
 * Reads the overrides of the table rights of roles: the cells of a role and a table.
 *
 * The answer does not depend on the table permission axis: whether to read it is up to the caller.
 */
final class TableCellProvider
{
	private const NOTHING_ALLOWED_VALUE = 0;

	/**
	 * @param int[]|null $roleIds null - all roles.
	 *
	 * @return TableCellChange[] Only overrides, ordered by role and permission identifier; rights are never null.
	 */
	public function getOverrides(?array $roleIds = null): array
	{
		$filter = [
			'%=PERMISSION_ID' => PermissionDictionary::getTableCellPermissionId('') . '%',
		];

		if ($roleIds !== null)
		{
			$roleIds = $this->normalizeRoleIds($roleIds);
			if (empty($roleIds))
			{
				return [];
			}

			$filter['@ROLE_ID'] = $roleIds;
		}

		$rows = PermissionTable::getList([
			'select' => ['ROLE_ID', 'PERMISSION_ID', 'VALUE'],
			'filter' => $filter,
			'order' => [
				'ROLE_ID' => 'ASC',
				'PERMISSION_ID' => 'ASC',
			],
		]);

		$pairs = [];
		while ($row = $rows->fetch())
		{
			$permissionId = (string)$row['PERMISSION_ID'];
			// MySQL matches the prefix without case: every row is checked again
			if (!PermissionDictionary::isTableCellPermission($permissionId))
			{
				continue;
			}

			$pairs[(int)$row['ROLE_ID']][$permissionId][] = (int)$row['VALUE'];
		}

		$overrides = [];
		foreach ($pairs as $roleId => $cells)
		{
			foreach ($cells as $permissionId => $values)
			{
				$rights = $this->buildRights($values);
				if ($rights === null)
				{
					continue;
				}

				$overrides[] = new TableCellChange(
					$roleId,
					(string)PermissionDictionary::getTableCodeFromCellPermission($permissionId),
					$rights,
				);
			}
		}

		return $overrides;
	}

	/**
	 * @param int[] $values Values of the rows of one cell.
	 *
	 * @return TableRights|null null when the rows of the cell are not a state the writer can leave.
	 */
	private function buildRights(array $values): ?TableRights
	{
		$values = array_values(array_unique($values));

		if (in_array(self::NOTHING_ALLOWED_VALUE, $values, true))
		{
			return count($values) === 1 ? new TableRights(false, false, false) : null;
		}

		$operations = PermissionDictionary::getTablePermissionCodes();
		if (array_diff($values, $operations) !== [])
		{
			return null;
		}

		$view = in_array(PermissionDictionary::BIC_TABLE_VIEW, $values, true);
		$edit = in_array(PermissionDictionary::BIC_TABLE_EDIT, $values, true);
		$export = in_array(PermissionDictionary::BIC_TABLE_EXPORT, $values, true);
		if (!$view && ($edit || $export))
		{
			return null;
		}

		return new TableRights($view, $edit, $export);
	}

	/**
	 * @param array $roleIds
	 *
	 * @return int[]
	 */
	private function normalizeRoleIds(array $roleIds): array
	{
		$result = [];
		foreach ($roleIds as $roleId)
		{
			$roleId = (int)$roleId;
			if ($roleId > 0)
			{
				$result[$roleId] = $roleId;
			}
		}

		return array_values($result);
	}
}
