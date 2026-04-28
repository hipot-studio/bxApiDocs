<?php

namespace Bitrix\Crm\Import\Contract;

use Bitrix\Crm\Import\Dto\ImportItemsCollection\ImportItem;
use Bitrix\Crm\Item;
use Bitrix\Crm\Result;

interface PostSaveHookInterface
{
	public function execute(Item $item, ImportItem $importItem): Result;
}
