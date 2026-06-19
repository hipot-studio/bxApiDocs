<?php

declare(strict_types=1);

namespace Bitrix\StaffTrackMobile\Infrastructure\ActionFilters;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\StaffTrackMobile\Public\Features\CheckInFeature;

final class NewCheckInEnabledFilter extends Base
{
	public function onBeforeAction(Event $event)
	{
		if (!(new CheckInFeature())->isEnabled())
		{
			$this->addError(new Error('NewCheckIn feature is disabled', 403));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}
