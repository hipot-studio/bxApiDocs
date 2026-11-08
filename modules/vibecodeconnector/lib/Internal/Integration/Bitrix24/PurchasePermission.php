<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24;

use Bitrix\Bitrix24\CurrentUser;
use Bitrix\Main\Loader;

final class PurchasePermission
{
	public function canPurchase(int $userId): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		return \CBitrix24::IsPortalAdmin($userId)
			|| CurrentUser::get()->isAdmin()
			|| (!\CBitrix24::isIntegrator($userId) && \CBitrix24::canAllBuyLicense());
	}
}
