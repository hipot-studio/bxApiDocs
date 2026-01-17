<?php

namespace Bitrix\Mail\Integration\HumanResources;

use Bitrix\HumanResources\Access\Permission\PermissionVariablesDictionary as HumanResourcesPermissionVariablesDictionary;
use Bitrix\Mail\Access\Permission\PermissionVariablesDictionary as MailPermissionVariablesDictionary;

class PermissionsMapper
{
	public static function getMappedPermissionArray(array $mailPermissions): array
	{
		$mappedPermissions = [];

		foreach ($mailPermissions as $permission)
		{
			$mappedPermissions[] = self::getMappedMailToDepartmentPermission($permission);
		}

		return $mappedPermissions;
	}

	public static function getMappedMailToDepartmentPermission(int $mailPermissionValue): int
	{
		return match ($mailPermissionValue)
		{
			MailPermissionVariablesDictionary::VARIABLE_ALL => HumanResourcesPermissionVariablesDictionary::VARIABLE_ALL,
			MailPermissionVariablesDictionary::VARIABLE_DEPARTMENT_WITH_SUBDEPARTMENTS => HumanResourcesPermissionVariablesDictionary::VARIABLE_SELF_DEPARTMENTS_SUB_DEPARTMENTS,
			MailPermissionVariablesDictionary::VARIABLE_SELF_DEPARTMENTS => HumanResourcesPermissionVariablesDictionary::VARIABLE_SELF_DEPARTMENTS,
			default => 0,
		};
	}
}
