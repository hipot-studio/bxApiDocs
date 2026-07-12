<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Integration\SocialNetwork;

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

	public static function isProjectFeatureEnabled(): bool
	{
		if (!self::isAvailable())
		{
			return false;
		}

		$projectLimitFeatureId = \Bitrix\Socialnetwork\Helper\Feature::PROJECTS_GROUPS;

		return \Bitrix\Socialnetwork\Helper\Feature::isFeatureEnabled($projectLimitFeatureId)
			|| \Bitrix\Socialnetwork\Helper\Feature::canTurnOnTrial($projectLimitFeatureId)
		;
	}

	private static function isAvailable(): bool
	{
		return Loader::includeModule('socialnetwork');
	}
}
