<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service;

class Timezone
{
	public function getTimezoneList(): array
	{
		$mainTimeZones = \CTimeZone::getZones();

		$timezones = [];
		foreach ($mainTimeZones as $timezoneId => $timezoneTitle)
		{
			if ($timezoneId === '')
			{
				continue;
			}

			$timezones[] = [
				'timezoneId' => $timezoneId,
				'title' => $timezoneTitle,
			];
		}

		return $timezones;
	}
}
