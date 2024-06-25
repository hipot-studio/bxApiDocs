<?php

namespace Bitrix\Crm\Security\Role\Manage\Entity;

use Bitrix\Crm\Item;
use Bitrix\Crm\Security\Role\Manage\DTO\EntityDTO;
use Bitrix\Crm\Security\Role\Manage\PermissionAttrPresets;
use Bitrix\Crm\Service;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Category\Entity\Category;
use Bitrix\Crm\Settings\InvoiceSettings;

class SmartInvoice implements PermissionEntity
{
	private function permissions(bool $isAutomationEnabled): array
	{
		return $isAutomationEnabled ?
			PermissionAttrPresets::crmEntityPresetAutomation()
			: PermissionAttrPresets::crmEntityPreset();
	}

	/**
	 * @return EntityDTO[]
	 */
	public function make(): array
	{
		if (!InvoiceSettings::getCurrent()->isSmartInvoiceEnabled())
		{
			return [];
		}

		$smartInvoiceFactory = Service\Container::getInstance()->getFactory(\CCrmOwnerType::SmartInvoice);
		if (!$smartInvoiceFactory)
		{
			return [];
		}

		$isAutomationEnabled = $smartInvoiceFactory->isAutomationEnabled();
		$perms = $this->permissions($isAutomationEnabled);

		$result = [];
		foreach ($smartInvoiceFactory->getCategories() as $category)
		{
			$entityName = htmlspecialcharsbx(Service\UserPermissions::getPermissionEntityType(\CCrmOwnerType::SmartInvoice, $category->getId()));
			$entityTitle = \CCrmOwnerType::GetDescription(\CCrmOwnerType::SmartInvoice);
			if ($smartInvoiceFactory->isCategoriesEnabled())
			{
				$entityTitle .= ' ' . $category->getSingleNameIfPossible();
			}

			$stages = $this->prepareStages($smartInvoiceFactory, $category);

			$result[] = new EntityDTO($entityName, $entityTitle, [Item::FIELD_NAME_STAGE_ID => $stages], $perms);
		}

		return $result;
	}

	private function prepareStages(Factory $smartInvoiceFactory, Category $category): array
	{
		$stages = [];
		foreach ($smartInvoiceFactory->getStages($category->getId()) as $stage)
		{
			$stages[htmlspecialcharsbx($stage->getStatusId())] = $stage->getName();
		}

		return $stages;
	}
}