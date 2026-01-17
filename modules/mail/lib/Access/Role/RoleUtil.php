<?php

namespace Bitrix\Mail\Access\Role;

use Bitrix\Mail\Internals\Access\AccessPermissionTable;
use Bitrix\Mail\Internals\Access\AccessRoleRelationTable;
use Bitrix\Mail\Internals\Access\AccessRoleTable;
use Bitrix\Main\Access\Role\RoleUtil as BaseRoleUtil;

class RoleUtil extends BaseRoleUtil
{
	protected static function getRoleTableClass(): string
	{
		return AccessRoleTable::class;
	}

	protected static function getRoleRelationTableClass(): string
	{
		return AccessRoleRelationTable::class;
	}

	protected static function getPermissionTableClass(): string
	{
		return AccessPermissionTable::class;
	}

	protected static function getRoleDictionaryClass(): string
	{
		return RoleDictionary::class;
	}

	public static function getDefaultMap(): array
	{
		return [
			RoleDictionary::ROLE_ADMIN => (new System\Admin())->getMap(),
			RoleDictionary::ROLE_DIRECTOR => (new System\Director())->getMap(),
			RoleDictionary::ROLE_EMPLOYEE => (new System\Employee())->getMap(),
		];
	}
}
