<?php

namespace Bitrix\Crm\Controller\Autorun;

use Bitrix\Crm\Controller\Autorun\Dto\PreparedData;
use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Factory;
use Bitrix\Main\Result;

final class Delete extends Base
{
	protected function processItem(Factory $factory, Item $item, PreparedData $data): Result
	{
		return $factory->getDeleteOperation($item)->launch();
	}
}
