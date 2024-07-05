<?php

namespace Bitrix\Stafftrack\Integration\Calendar;

use Bitrix\Main;
use Bitrix\StaffTrack\Trait\Singleton;

class SettingsProvider
{
	use Singleton;

	public function getSettings(): array
	{
		if (!Main\Loader::includeModule('calendar'))
		{
			return [];
		}

		$calendarSettings = \CCalendar::GetSettings();
		$weekStart = $calendarSettings['week_start'] ?? '';

		return [
			'firstWeekday' => \CCalendar::IndByWeekDay($weekStart) + 1,
		];
	}
}