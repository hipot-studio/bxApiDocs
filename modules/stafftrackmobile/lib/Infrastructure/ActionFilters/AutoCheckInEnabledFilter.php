<?php

declare(strict_types=1);

namespace Bitrix\StaffTrackMobile\Infrastructure\ActionFilters;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\StaffTrack\Public\Provider\CheckInSettingsProvider;

final class AutoCheckInEnabledFilter extends Base
{
	public function onBeforeAction(Event $event)
	{
		if (!(new CheckInSettingsProvider())->isAutoCheckInEnabled())
		{
			$this->addError(new Error('Auto check-in is disabled', 403));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}
