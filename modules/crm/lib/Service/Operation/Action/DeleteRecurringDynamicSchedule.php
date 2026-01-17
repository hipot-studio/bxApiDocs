<?php

namespace Bitrix\Crm\Service\Operation\Action;

use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Recurring\Entity\Item\DynamicExist;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Operation;
use Bitrix\Main\Result;

class DeleteRecurringDynamicSchedule extends Operation\Action
{
	public function process(Item $item): Result
	{
		$itemBeforeSave = $this->getItemBeforeSave();

		$factory = Container::getInstance()->getFactory($item->getEntityTypeId());
		if ($factory?->isRecurringSupported() && $itemBeforeSave?->getIsRecurring())
		{
			$itemIdentifier = new ItemIdentifier($itemBeforeSave->getEntityTypeId(), $itemBeforeSave->getId());
			$recurringItem = DynamicExist::loadByItemIdentifier($itemIdentifier);
			if ($recurringItem)
			{
				return $recurringItem->delete();
			}
		}

		return new Result();
	}
}