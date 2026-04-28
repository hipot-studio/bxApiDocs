<?php

namespace Bitrix\Crm\Controller\Autorun;

use Bitrix\Crm\Controller\Autorun\Dto\PreparedData;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Main\Result;

final class RefreshAccountingData extends Base
{
	protected function getSelect(): array
	{
		return [Item::FIELD_NAME_ID];
	}

	protected function processItem(Factory $factory, Item $item, PreparedData $data): Result
	{
		$identifier = ItemIdentifier::createByItem($item);

		if (!Container::getInstance()->getUserPermissions()->item()->canUpdateItemIdentifier($identifier))
		{
			return (new Result())->addError(ErrorCode::getAccessDeniedError());
		}

		return $this->refreshAccountingData($identifier);
	}

	private function refreshAccountingData(ItemIdentifier $identifier): Result
	{
		$result = new Result();

		if ($identifier->getEntityTypeId() === \CCrmOwnerType::Lead)
		{
			\CCrmLead::RefreshAccountingData([$identifier->getEntityId()]);
		}
		elseif ($identifier->getEntityTypeId() === \CCrmOwnerType::Deal)
		{
			\CCrmDeal::RefreshAccountingData([$identifier->getEntityId()]);
		}
		else
		{
			$result->addError(ErrorCode::getEntityTypeNotSupportedError($identifier->getEntityTypeId()));
		}

		return $result;
	}
}
