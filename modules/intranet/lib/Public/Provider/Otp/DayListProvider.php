<?php

namespace Bitrix\Intranet\Public\Provider\Otp;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Access\Otp\UserPermission;
use Bitrix\Main\Localization\Loc;

class DayListProvider
{
	/**
	 * @return array<int, string> key is an allowed period value, value is a menu item caption
	 */
	public function getList(?User $targetUser = null): array
	{
		$days = [];

		if ($targetUser !== null && (new UserPermission($targetUser))->canSetIndefinitePeriod())
		{
			$days[0] = Loc::getMessage('INTRANET_DAY_LIST_PROVIDER_NO_DAYS');
		}

		for ($i = 1; $i <= 10; $i++)
		{
			$days[$i] = FormatDate('ddiff', time() - 60 * 60 * 24 * $i);
		}

		return $days;
	}

	public function isAllowedPeriod(User $targetUser, int $days): bool
	{
		return array_key_exists($days, $this->getList($targetUser));
	}

	/**
	 * Checks a value as it came from the request, before any type coercion loses its original form
	 */
	public function isAllowedRawPeriod(User $targetUser, mixed $days): bool
	{
		if (!is_int($days) && !is_string($days))
		{
			return false;
		}

		if ((string)(int)$days !== (string)$days)
		{
			return false;
		}

		return $this->isAllowedPeriod($targetUser, (int)$days);
	}
}
