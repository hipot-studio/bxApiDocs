<?php

namespace Bitrix\BIConnector\Access\Update;

use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Access\Permission\PermissionTable;
use Bitrix\BIConnector\Access\Role\RoleUtil;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Hands the right to edit row rules over to the roles that reach the analyst workspace, the same roles
 * a new portal gives it to: the section of the rules opens only with the workspace.
 *
 * A role that already has a row of the new right, whatever its value, is left alone.
 */
final class RowRulesPermissionGrant
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
				'PERMISSION_ID' => PermissionDictionary::BIC_SETTINGS_EDIT_ROW_RULES,
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
			$result->addError(new Error($exception->getMessage(), 'BICONNECTOR_ROW_RULES_GRANT_INSERT_FAILED'));
			Logger::logErrors($result->getErrors(), ['source' => 'row_rules_permission_grant']);

			return $result;
		}

		return $result->setData(['grantedRoleIds' => $roleIds]);
	}

	/**
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
					PermissionDictionary::BIC_EXTERNAL_DASHBOARD_CONFIG,
					PermissionDictionary::BIC_SETTINGS_EDIT_ROW_RULES,
				],
			],
		])->fetchAll();

		$candidates = [];
		$holders = [];
		foreach ($rows as $row)
		{
			$roleId = (int)$row['ROLE_ID'];
			$permissionId = (string)$row['PERMISSION_ID'];

			if ($permissionId === (string)PermissionDictionary::BIC_SETTINGS_EDIT_ROW_RULES)
			{
				$holders[$roleId] = true;

				continue;
			}

			// A zero value is a right taken away, not a right held.
			if ((int)$row['VALUE'] === PermissionDictionary::VALUE_YES)
			{
				$candidates[$roleId] = true;
			}
		}

		return array_values(array_diff(array_keys($candidates), array_keys($holders)));
	}
}
