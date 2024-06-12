<?php

namespace Bitrix\Crm\Security\Role\Manage\Entity;

use Bitrix\Crm\Security\Role\Manage\DTO\EntityDTO;
use Bitrix\Crm\Security\Role\Manage\PermissionAttrPresets;
use Bitrix\Crm\Service\Container;
use CCrmOwnerType;

class Order implements PermissionEntity
{
	/**
	 * @return EntityDTO[]
	 */
	public function make(): array
	{
		$name = Container::getInstance()->getFactory(CCrmOwnerType::Order)->getEntityDescription();
		return [new EntityDTO('ORDER', $name, [], PermissionAttrPresets::crmEntityPresetAutomation())];
	}
}