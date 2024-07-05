<?php

namespace Bitrix\StaffTrack;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

class Feature
{
	public const MODULE_ID = 'stafftrack';
	public const CHECK_IN_SETTINGS_KEY = 'feature_check_in_enabled_by_settings';

	/**
	 * @return bool
	 */
	public static function isCheckInEnabled(): bool
	{
		return Loader::includeModule('stafftrackmobile');
	}

	public static function isCheckInEnabledBySettings(): bool
	{
		return Option::get(self::MODULE_ID, self::CHECK_IN_SETTINGS_KEY, 'Y') === 'Y';
	}

	public static function turnCheckInSettingOn(): void
	{
		Option::set(self::MODULE_ID, self::CHECK_IN_SETTINGS_KEY, 'Y');
	}

	public static function turnCheckInSettingOff(): void
	{
		Option::set(self::MODULE_ID, self::CHECK_IN_SETTINGS_KEY, 'N');
	}
}
