<?php

namespace Bitrix\Mail\Access\Repository;

use Bitrix\Mail\Internals\Access\AccessPermissionTable;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

class PermissionRepository
{
	/**
	 * @param array<int> $roleIds
	 * @return array{PERMISSION_ID: int}
	 */
	public function getEffectivePermissionsByRoleIds(array $roleIds): array
	{
		$permissions = [];

		$rows =
			AccessPermissionTable::query()
				->addSelect("PERMISSION_ID")
				->addSelect("VALUE")
				->whereIn("ROLE_ID", $roleIds)
				->fetchAll()
		;

		foreach ($rows as $row)
		{
			$permissionId = (string)$row["PERMISSION_ID"];
			$value = (int)$row["VALUE"];

			$permissions[$permissionId] = max($permissions[$permissionId] ?? 0, $value);
		}

		return $permissions;
	}

	/**
	 * @return array{PERMISSION_ID: int}
	 *
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function getPermissionsForRole(int $roleId): array
	{
		$permissions = [];

		$rows =
			AccessPermissionTable::query()
				->setSelect(['PERMISSION_ID', 'VALUE'])
				->where('ROLE_ID', $roleId)
				->fetchAll()
		;

		foreach ($rows as $row)
		{
			$permissions[$row['PERMISSION_ID']] = (int)$row['VALUE'];
		}

		return $permissions;
	}

	public function deleteByRoleId(int $roleId): void
	{
		AccessPermissionTable::deleteList(['=ROLE_ID' => $roleId]);
	}

	public function add(int $roleId, string $permissionId, int $value): void
	{
		AccessPermissionTable::add([
			'ROLE_ID' => $roleId,
			'PERMISSION_ID' => $permissionId,
			'VALUE' => $value,
		]);
	}

	/**
	 * @param $permissions list<array{
	 *    ROLE_ID: int,
	 *    PERMISSION_ID: string,
	 *    VALUE: int
	 *  }>
	 * @throws \Bitrix\Main\Db\SqlQueryException
	 */
	public function addMultiple(array $permissions): void
	{
		if (empty($permissions))
		{
			return;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$tableName = AccessPermissionTable::getTableName();
		$primaryKeys = ['ROLE_ID', 'PERMISSION_ID'];

		$sqlQueries = $helper->prepareMergeMultiple($tableName, $primaryKeys, $permissions);
		foreach ($sqlQueries as $sql)
		{
			$connection->query($sql);
		}
	}

	public function deleteByRoleIds(array $roleIds): void
	{
		if (empty($roleIds))
		{
			return;
		}

		AccessPermissionTable::deleteList(['@ROLE_ID' => $roleIds]);
	}

	public function deleteByRoleIdsAndPermissionIds(array $roleIds, array $permissionIds): void
	{
		if (empty($roleIds) || empty($permissionIds))
		{
			return;
		}

		AccessPermissionTable::deleteList([
			'@ROLE_ID' => $roleIds,
			'@PERMISSION_ID' => $permissionIds,
		]);
	}
}
