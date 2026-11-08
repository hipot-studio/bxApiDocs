<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Infrastructure\ActionFilters;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\StaffTrackMobile\Public\Features\CheckInFeature;

final class LegacyCheckInDisabledFilter extends Base
{
	public function onBeforeAction(Event $event)
	{
		if (!Loader::includeModule('stafftrackmobile'))
		{
			return null;
		}

		if ((new CheckInFeature())->isEnabled())
		{
			$this->addError(new Error('NewCheckIn feature is enabled, legacy check-in endpoints are disabled', 403));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}
