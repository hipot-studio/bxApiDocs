<?php

namespace Bitrix\Crm\Security\Role\Manage\Entity;

use Bitrix\Crm\Security\Role\Manage\DTO\EntityDTO;
use Bitrix\Crm\Security\Role\Manage\PermissionAttrPresets;
use Bitrix\Crm\Service\Container;
use CCrmOwnerType;

class Quote implements PermissionEntity
{
	private function permissions(): array
	{
		return array_merge(
			PermissionAttrPresets::crmEntityPreset(),
			PermissionAttrPresets::crmEntityKanbanHideSum()
		);
	}

	/**
	 * @return EntityDTO[]
	 */
	public function make(): array
	{
		$name = Container::getInstance()->getFactory(CCrmOwnerType::Quote)->getEntityDescription();
		return [
			new EntityDTO('QUOTE', $name, [], $this->permissions())
		];
	}
}