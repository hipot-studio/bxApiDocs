<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\OnFilterUsersByAccessEvent;

use Bitrix\Im\V2\Chat\ExternalChat\Event\FilterUsersByAccessEvent;
use Bitrix\Main\Error;
use Bitrix\Main\EventResult;
use Bitrix\Socialnetwork\Helper\Feature;

class CheckAccess
{
	public static function execute(FilterUsersByAccessEvent $event): ?EventResult
	{
		if (!\Bitrix\Socialnetwork\V2\Feature::isNewProjectsOn())
		{
			return null;
		}

		$restricted =
			!Feature::isFeatureEnabled(Feature::PROJECTS_GROUPS)
			&& !Feature::canTurnOnTrial(Feature::PROJECTS_GROUPS)
		;

		if ($restricted)
		{
			return new EventResult(
				EventResult::SUCCESS,
				[
					'userIds' => $event->getUserIds(),
					'error' => new Error('COLLAB_TARIFF_RESTRICTED', 'COLLAB_TARIFF_RESTRICTED'),
				]
			);
		}

		return null;
	}
}
