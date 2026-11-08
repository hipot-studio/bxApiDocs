<?php

namespace Bitrix\Crm\Security\Role\Manage\Entity;

use Bitrix\Crm\Security\Role\Manage\DTO\EntityDTO;
use Bitrix\Crm\Security\Role\Manage\PermissionAttrPresets;
use Bitrix\Crm\Service\Container;
use CCrmOwnerType;
use CCrmStatus;

class Lead implements PermissionEntity
{
	private function permissions(): array
	{
		$inheritDescription = PermissionAttrPresets::stageInheritDescription(CCrmOwnerType::Lead, null, null);

		return array_merge(
			PermissionAttrPresets::crmEntityPresetAutomation(
				true,
				$inheritDescription,
				withObserversWrite: true,
				withObserversWriteByStages: true,
			),
			PermissionAttrPresets::crmEntityKanbanHideSum(),
			PermissionAttrPresets::crmStageTransition(CCrmStatus::GetStatusListEx('STATUS'), $inheritDescription)
		);
	}

	/**
	 * @return EntityDTO[]
	 */
	public function make(): array
	{
		$fields = ['STATUS_ID' => CCrmStatus::GetStatusListEx('STATUS', false)];

		$name = Container::getInstance()->getFactory(CCrmOwnerType::Lead)->getEntityDescription();

		return [
			new EntityDTO(
				'LEAD',
				$name,
				$fields,
				$this->permissions(),
				null,
				'customer-card',
				'--ui-color-accent-turquoise',
			)
		];
	}

}
