<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;

class Feature
{
	public static function isNewProjectsOn(): bool
	{
		return Option::get('socialnetwork', 'new_projects', 'N') === 'Y';
	}

	public static function isOldPortalForNewProject(): bool
	{
		static $isOldPortal = null;

		if ($isOldPortal === null)
		{
			$isOldPortal = Container::getInstance()->getPortalService()->isOld(
				new DateTime('2026-05-31', 'Y-m-d')
			);
		}

		return $isOldPortal;
	}
}
