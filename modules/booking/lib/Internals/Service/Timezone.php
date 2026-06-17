<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service;

class Timezone
{
	private static array|null $zonesCache = null;

	public function getTimezoneList(): array
	{
		$timezones = [];
		foreach ($this->getZones() as $timezoneId => $timezoneTitle)
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

	public function isValid(string|null $timeZone): bool
	{
		if ($timeZone === null || $timeZone === '')
		{
			return false;
		}

		return isset($this->getZones()[$timeZone]);
	}

	private function getZones(): array
	{
		return self::$zonesCache ??= \CTimeZone::getZones();
	}
}
