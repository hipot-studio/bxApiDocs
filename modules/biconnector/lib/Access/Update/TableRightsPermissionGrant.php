<?php

namespace Bitrix\BIConnector\Access\Update;

use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Access\Permission\PermissionTable;
use Bitrix\BIConnector\Access\Role\RoleUtil;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Hands the new general right over to the roles that already configure the rights of the module.
 *
 * The right is new, so on an updated portal nobody holds it, and the section it opens would be
 * closed to the very administrators who used to configure the rights of the module. The receiver is
 * chosen by what a role can do today rather than by its name: a portal is free to have any set of
 * roles, and only the right to configure rights says that configuring them is this role's business.
 *
 * A role that already has a row of the new right is left alone, its value included. A row means a
 * decision someone has already made - the previous run of this one, or a person who has taken the
 * right away - and neither is ours to redo.
 */
final class TableRightsPermissionGrant
{
	/**
	 * @return Result Data: ['grantedRoleIds' => int[]].
	 */
	public static function grant(): Result
	{
		$result = new Result();

		$roleIds = self::getRoleIdsToGrant();
		if (empty($roleIds))
		{
			return $result->setData(['grantedRoleIds' => []]);
		}

		$rows = array_map(
			static fn(int $roleId): array => [
				'ROLE_ID' => $roleId,
				'PERMISSION_ID' => PermissionDictionary::BIC_SETTINGS_EDIT_TABLE_RIGHTS,
				'VALUE' => PermissionDictionary::VALUE_YES,
			],
			$roleIds,
		);

		try
		{
			// No transaction of its own: a single multi-row insert either lands or does not, and the
			// caller may already be inside one.
			RoleUtil::insertPermissions($rows);
		}
		catch (\Throwable $exception)
		{
			$result->addError(new Error($exception->getMessage(), 'BICONNECTOR_TABLE_RIGHTS_GRANT_INSERT_FAILED'));
			Logger::logErrors($result->getErrors(), ['source' => 'table_rights_permission_grant']);

			return $result;
		}

		return $result->setData(['grantedRoleIds' => $roleIds]);
	}

	/**
	 * Both rows are read by one query and told apart here: the roles that configure rights and the
	 * roles the new right has already reached live in the same table, and the difference between two
	 * sets is cheaper than a second read.
	 *
	 * @return int[]
	 */
	private static function getRoleIdsToGrant(): array
	{
		$rows = PermissionTable::getList([
			'select' => [
				'ROLE_ID',
				'PERMISSION_ID',
				'VALUE',
			],
			'filter' => [
				'@PERMISSION_ID' => [
					PermissionDictionary::BIC_SETTINGS_EDIT_RIGHTS,
					PermissionDictionary::BIC_SETTINGS_EDIT_TABLE_RIGHTS,
				],
			],
		])->fetchAll();

		$candidates = [];
		$holders = [];
		foreach ($rows as $row)
		{
			$roleId = (int)$row['ROLE_ID'];
			$permissionId = (string)$row['PERMISSION_ID'];

			if ($permissionId === (string)PermissionDictionary::BIC_SETTINGS_EDIT_TABLE_RIGHTS)
			{
				$holders[$roleId] = true;

				continue;
			}

			// A row with a zero value is a right taken away, not a right held: the section is closed to
			// that role today and the new one must not open it.
			if ((int)$row['VALUE'] === PermissionDictionary::VALUE_YES)
			{
				$candidates[$roleId] = true;
			}
		}

		return array_values(array_diff(array_keys($candidates), array_keys($holders)));
	}
}
