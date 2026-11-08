<?php

declare(strict_types=1);

namespace Bitrix\IntranetMobile\Qualification;

use Bitrix\Bitrix24\CurrentUser;
use Bitrix\Bitrix24\Marketing\Wizard\Access;
use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;

final class DefaultMobileQualificationPresetAccessChecker implements MobileQualificationPresetAccessChecker
{
	public function isAdmin(): bool
	{
		return Loader::includeModule('bitrix24') && CurrentUser::get()->isAdmin();
	}

	public function isExternalUser(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		return in_array(CurrentUser::get()->getExternalAuthId(), UserTable::getExternalUserTypes(), true);
	}

	public function isPortalEligible(): bool
	{
		return Loader::includeModule('bitrix24')
			&& (Access::isTest() || Access::isPortalCreatedAfterMinimumDate());
	}
}
