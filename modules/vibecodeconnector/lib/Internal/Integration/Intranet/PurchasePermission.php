<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Intranet;

use Bitrix\Intranet\CurrentUser;
use Bitrix\Main\Loader;

final class PurchasePermission
{
	public function canPurchase(int $userId): bool
	{
		if (!Loader::includeModule('intranet'))
		{
			return false;
		}

		$currentUser = CurrentUser::get();

		return (int)$currentUser->getId() === $userId && $currentUser->isAdmin();
	}
}
