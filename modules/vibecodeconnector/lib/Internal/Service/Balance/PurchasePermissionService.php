<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Balance;

use Bitrix\Main\Service\MicroService\Client;
use Bitrix\Vibecodeconnector\Internal\Integration;

final class PurchasePermissionService
{
	public function canPurchase(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		if (Client::getPortalType() === Client::TYPE_BITRIX24)
		{
			return (new Integration\Bitrix24\PurchasePermission())->canPurchase($userId);
		}

		return (new Integration\Intranet\PurchasePermission())->canPurchase($userId);
	}
}
