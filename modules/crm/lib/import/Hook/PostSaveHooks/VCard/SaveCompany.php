<?php

namespace Bitrix\Crm\Import\Hook\PostSaveHooks\VCard;

use Bitrix\Crm\Import\Contract\PostSaveHookInterface;
use Bitrix\Crm\Import\Dto\ImportItemsCollection\ImportItem;
use Bitrix\Crm\Item;
use Bitrix\Crm\Result;
use Bitrix\Crm\Service\Container;
use CCrmOwnerType;

final class SaveCompany implements PostSaveHookInterface
{
	public function execute(Item $item, ImportItem $importItem): Result
	{
		$companyFields = $importItem->values['COMPANY'] ?? null;
		if (empty($companyFields))
		{
			return Result::success();
		}

		$title = $companyFields['TITLE'] ?? null;
		if (empty($title))
		{
			return Result::success();
		}

		$items = Container::getInstance()
			->getFactory(CCrmOwnerType::Company)
			?->getItems([
				'select' => [
					Item\Company::FIELD_NAME_ID,
				],
				'filter' => [
					Item\Company::FIELD_NAME_TITLE => $title,
				],
				'limit' => 1,
			]);

		$company = $items[0] ?? null;
		if (empty($company))
		{
			$company = Container::getInstance()->getFactory(CCrmOwnerType::Company)?->createItem([]);
		}

		$company->setFromCompatibleData($companyFields);

		if ($company->isNew())
		{
			$saveResult = Container::getInstance()
				->getFactory(CCrmOwnerType::Company)
				?->getAddOperation($company)
				->disableCheckRequiredUserFields()
				->launch();
		}
		else
		{
			$saveResult = Container::getInstance()
				->getFactory(CCrmOwnerType::Company)
				?->getUpdateOperation($company)
				->disableCheckRequiredUserFields()
				->launch();
		}

		if (!$saveResult->isSuccess())
		{
			return Result::fail($saveResult->getErrorCollection());
		}

		$item->setCompanyId($company->getId());
		Container::getInstance()
			->getFactory(CCrmOwnerType::Contact)
			?->getImportOperation($item)
			?->launch();

		return Result::success();
	}
}
