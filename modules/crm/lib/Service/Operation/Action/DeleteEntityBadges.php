<?php

namespace Bitrix\Crm\Service\Operation\Action;

use Bitrix\Crm\Badge\Badge;
use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Operation\Action;
use Bitrix\Crm\Service\Timeline\Monitor;
use Bitrix\Main\Result;

class DeleteEntityBadges extends Action
{
	public function process(Item $item): Result
	{
		$itemIdentifier = new ItemIdentifier($item->getEntityTypeId(), $item->getId(), $item->getCategoryId());

		Badge::deleteByEntity($itemIdentifier);

		Monitor::getInstance()->onBadgesSync($itemIdentifier);

		return new Result();
	}
}