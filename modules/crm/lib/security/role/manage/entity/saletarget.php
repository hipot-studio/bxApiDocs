<?php

namespace Bitrix\Crm\Security\Role\Manage\Entity;

use Bitrix\Crm\Security\Role\Manage\AttrPreset\UserDepartmentAndOpened;
use Bitrix\Crm\Security\Role\Manage\DTO\EntityDTO;
use Bitrix\Crm\Security\Role\Manage\PermissionAttrPresets;
use Bitrix\Crm\Security\Role\Manage\Permissions\Read;
use Bitrix\Crm\Security\Role\Manage\Permissions\Write;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\ControlMapper\DependentVariables;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\ControlMapper\Toggler;
use Bitrix\Main\Localization\Loc;

class SaleTarget implements PermissionEntity
{
	public const ENTITY_CODE = 'SALETARGET';

	private function permissions(): array
	{
		$hierarchy = (new UserDepartmentAndOpened())
			->exclude(UserDepartmentAndOpened::OPEN)
			->exclude(UserDepartmentAndOpened::TEAM)
			->exclude(UserDepartmentAndOpened::SUBTEAMS)
		;

		return [
			new Read(
				$hierarchy->getVariants(),
				(new DependentVariables\UserDepartmentAndOpenedAsAttributes())
					->setPermissionPreset($hierarchy)
					->addSelectedVariablesAlias(
						[
							UserDepartmentAndOpened::SELF,
							UserDepartmentAndOpened::DEPARTMENT,
							UserDepartmentAndOpened::SUBDEPARTMENTS,
							UserDepartmentAndOpened::ALL,
						],
						Loc::getMessage('CRM_SECURITY_ROLE_PERMS_TYPE_X'),
					)
				,
			),
			new Write(PermissionAttrPresets::switchAll(), new Toggler()),
		];
	}
	/**
	 * @return EntityDTO[]
	 */
	public function make(): array
	{
		$name = GetMessage('CRM_SECURITY_ROLE_ENTITY_TYPE_SALETARGET');

		return [new EntityDTO(self::ENTITY_CODE, $name, [], $this->permissions())];
	}
}
