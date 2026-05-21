<?php

namespace Bitrix\Crm\Security\Role\Manage\Entity;

use Bitrix\Crm\Security\Role\Manage\DTO\EntityDTO;
use Bitrix\Crm\Security\Role\Manage\PermissionAttrPresets;
use Bitrix\Crm\Security\Role\Manage\Permissions\Event\Read;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\ControlMapper\Toggler;

final class Event implements PermissionEntity
{
	public const ENTITY_CODE = 'EVENT';

	private function permissions(): array
	{
		return [
			new Read(PermissionAttrPresets::switchAll()),
		];
	}
	/**
	 * @return EntityDTO[]
	 */
	public function make(): array
	{
		$name = GetMessage('CRM_SECURITY_ROLE_ENTITY_TYPE_EVENT');

		return [new EntityDTO(self::ENTITY_CODE, $name, [], $this->permissions())];
	}
}
