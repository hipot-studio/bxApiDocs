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
			$cloudPortalCreationDate = Option::get('socialnetwork', 'new_projects_old_portal_date', '2026-06-30');

			$isOldPortal = Container::getInstance()->getPortalService()->isOld(
				new DateTime($cloudPortalCreationDate, 'Y-m-d')
			);
		}

		return $isOldPortal;
	}
}
