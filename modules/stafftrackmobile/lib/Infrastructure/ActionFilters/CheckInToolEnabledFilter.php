<?php

declare(strict_types=1);

namespace Bitrix\StaffTrackMobile\Infrastructure\ActionFilters;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\StaffTrack\Feature;

final class CheckInToolEnabledFilter extends Base
{
	public function onBeforeAction(Event $event)
	{
		if (!Feature::isCheckInEnabledBySettings())
		{
			$this->addError(new \Bitrix\Main\Error('Feature is disabled', 403));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}
	}
}
