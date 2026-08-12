<?php

namespace Bitrix\StaffTrack\Internal\Integration\Timeman;

use Bitrix\Bitrix24\Feature;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;

class BaseService
{
	/**
	 * Timeman is loadable and the current user can actually use it right now.
	 *
	 * @return bool
	 * @throws LoaderException
	 */
	public static function isAvailable(): bool
	{
		return
			Loader::includeModule('timeman')
			&& \CBXFeatures::IsFeatureEnabled('timeman')
			&& \CTimeMan::CanUse()
		;
	}

	/**
	 * Timeman is allowed by the portal's license — in tariff on cloud (regardless of
	 * install state, since admin can re-enable via tools settings), or in box edition
	 * AND loadable on box (where there is no admin-toggle layer above the module itself).
	 *
	 * @return bool
	 */
	public static function isAllowedByLicense(): bool
	{
		if (Loader::includeModule('bitrix24'))
		{
			return Feature::isFeatureEnabled('timeman');
		}

		return Loader::includeModule('timeman')
			&& \CBXFeatures::IsFeatureEnabled('timeman');
	}
}
