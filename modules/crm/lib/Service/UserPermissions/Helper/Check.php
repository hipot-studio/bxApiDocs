<?php

namespace Bitrix\Crm\Service\UserPermissions\Helper;

use Bitrix\Main\Loader;

class Check
{
	public static function getOrderIdByCheckId(int $checkId): int
	{
		if ($checkId > 0 && Loader::includeModule('sale'))
		{
			$check = \Bitrix\Sale\Cashbox\CheckManager::getObjectById($checkId);

			return (int)$check?->getField('ORDER_ID');
		}

		return 0;
	}
}
