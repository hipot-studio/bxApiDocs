<?php

declare(strict_types=1);

namespace Bitrix\StaffTrackMobile\Infrastructure\ActionFilters;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\StaffTrack\Feature;

final class CheckInAvailableByRegionFilter extends Base
{
	public function onBeforeAction(Event $event)
	{
		if (!Feature::isGeoFeaturesAvailableByRegion())
		{
			$this->addError(new Error('Feature is not available in this region', 403));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}
