<?php

namespace Bitrix\Crm\Security\Role\Manage\Entity;

use Bitrix\Crm\Security\Role\Manage\DTO\EntityDTO;
use Bitrix\Crm\Security\Role\Manage\PermissionAttrPresets;
use Bitrix\Crm\Security\Role\Manage\Permissions\Configuration\OperationPermissions;
use Bitrix\Crm\Security\Role\Manage\Permissions\WriteConfig;

class CrmConfig implements PermissionEntity
{
	public const ENTITY_CODE = 'CONFIG';

	private function permissions(): array
	{
		return array_merge(
			OperationPermissions::all(),
			[new WriteConfig(PermissionAttrPresets::allowedYesNo(), showExplanation: true)],
		);
	}

	public function make(): array
	{
		$name = GetMessage('CRM_SECURITY_ROLE_ENTITY_TYPE_CRM_CONFIG');

		return [
			new EntityDTO(self::ENTITY_CODE, $name, [], $this->permissions())
		];
	}
}
