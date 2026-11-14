<?php

namespace Bitrix\Crm\Controller\Autorun;

use Bitrix\Crm\Cleaning\CleaningManager;
use Bitrix\Crm\Component\EntityList\ClientTab\ClientTabContext;
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

		// the deleted item forgets its id
		$itemId = $item->getId();
		$operation = $factory->getDeleteOperation($item, $context);
		$result = $this->launchInTransactionWrapper(new TransactionWrapper($operation))->launch();

		if (
			$result->isSuccess()
			&& $item->getEntityTypeId() === \CCrmOwnerType::Contact
			&& ClientTabContext::isClientTabGridId($item->getEntityTypeId(), $data->gridId)
		)
		{
			// the card of the owner reads its field "Client" again right after the process of its client tab:
			// the links of the deleted contact are to be gone by then, not after the deferred cleaning,
			// or the field would show the contact as hidden and send it back with the next save of the card
			CleaningManager::unbindContactFromOwners($itemId);
		}

		return $result;
	}
}
