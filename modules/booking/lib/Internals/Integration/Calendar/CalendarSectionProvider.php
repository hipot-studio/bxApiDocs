<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Calendar;

use Bitrix\Calendar\Core\Event\Tools\Dictionary;
use Bitrix\Main\Loader;

class CalendarSectionProvider
{
	public function getSectionForUser(int $userId): array
	{
		if (!Loader::includeModule('calendar'))
		{
			return [];
		}

		return \CCalendarSect::GetSectionForOwner(Dictionary::CALENDAR_TYPE['user'], $userId);
	}
}
