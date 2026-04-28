<?php

namespace Bitrix\Sign\Service\Integration\Crm;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory\SmartDocument;
use Bitrix\Main\Loader;

final class AccessService
{
	public function canReadSmartDocumentContacts(): bool
	{
		if (!Loader::includeModule('crm'))
		{
			return false;
		}

		$factory = Container::getInstance()->getFactory(\CCrmOwnerType::Contact);
		$category = $factory?->getCategoryByCode(SmartDocument::CONTACT_CATEGORY_CODE);
		if (!$category)
		{
			return true;
		}

		return Container::getInstance()
			->getUserPermissions()
			->entityType()
			->canReadItemsInCategory(\CCrmOwnerType::Contact, $category->getId());
	}
}
