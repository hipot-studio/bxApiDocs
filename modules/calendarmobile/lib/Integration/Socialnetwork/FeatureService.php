<?php

namespace Bitrix\CalendarMobile\Integration\Socialnetwork;

use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\V2\Feature;

class FeatureService
{
	public static function isNewProjectsOn(): bool
	{
		if (!self::isAvailable())
		{
			return false;
		}

		return class_exists(Feature::class) && Feature::isNewProjectsOn();
	}

	private static function isAvailable(): bool
	{
		return Loader::includeModule('socialnetwork');
	}
}
