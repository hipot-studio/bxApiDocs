<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\OnCollectTariffRestrictions;

use Bitrix\Im\V2\TariffLimit\Event\CollectRestrictionsEvent;
use Bitrix\Main\EventResult;
use Bitrix\Socialnetwork\Helper\Feature;

class TariffRestrictionHandler
{
	public static function execute(CollectRestrictionsEvent $event): EventResult
	{
		$restrictions = $event->getRestrictions()->withAdded(new CollabRestriction(
			isAvailable: Feature::isFeatureEnabled(Feature::PROJECTS_GROUPS)
				|| Feature::canTurnOnTrial(Feature::PROJECTS_GROUPS),
			isCopyAvailable: Feature::isFeatureEnabled(Feature::PROJECTS_COPY),
		));

		return new EventResult(EventResult::SUCCESS, ['restrictions' => $restrictions]);
	}
}
