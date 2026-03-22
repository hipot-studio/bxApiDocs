<?php

namespace Bitrix\Crm\Controller\Autorun;

use Bitrix\Crm\Controller\Autorun\Dto\PreparedData;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Service\Operation\TransactionWrapper;
use Bitrix\Main\Result;

final class Delete extends Base
{
	protected function isWrapItemProcessingInTransaction(): bool
	{
		return false;
	}

	protected function processItem(Factory $factory, Item $item, PreparedData $data): Result
	{
		$context = clone \Bitrix\Crm\Service\Container::getInstance()->getContext();
		$context->setAnalytics([
			'c_section' => Dictionary::getSectionByEntityType($item->getEntityTypeId()),
			'c_sub_section' => Dictionary::SUB_SECTION_LIST,
		]);

		$operation = $factory->getDeleteOperation($item, $context);

		return (new TransactionWrapper($operation))->launch();
	}
}
