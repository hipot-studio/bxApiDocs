<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Calendar\Service;

use Bitrix\Socialnetwork\Integration\Calendar\Calendar;

class CalendarSettingsService
{
	private const WEEK_DAYS_MAP = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];

	public function getFormattedSettings(): array
	{
		$result = $this->getDefaults();

		$site = \CSite::GetByID(SITE_ID)->fetch();
		$weekDay = $site['WEEK_START'] ?? null;
		if ((string)$weekDay !== '' && isset(self::WEEK_DAYS_MAP[$weekDay]))
		{
			$result['WEEK_START'] = self::WEEK_DAYS_MAP[$weekDay];
		}

		$calendarSettings = Calendar::getSettings();
		if (empty($calendarSettings))
		{
			return $result;
		}

		if (is_array($calendarSettings['week_holidays'] ?? null))
		{
			$result['WEEKEND'] = $calendarSettings['week_holidays'];
		}

		if ((string)($calendarSettings['year_holidays'] ?? '') !== '')
		{
			$result['HOLIDAYS'] = $this->parseHolidays($calendarSettings['year_holidays']);
		}

		$result['HOURS']['START'] = $this->parseTime(
			(string)($calendarSettings['work_time_start'] ?? ''),
			$result['HOURS']['START'],
		);

		$result['HOURS']['END'] = $this->parseTime(
			(string)($calendarSettings['work_time_end'] ?? ''),
			$result['HOURS']['END'],
		);

		return $result;
	}

	private function getDefaults(): array
	{
		return [
			'HOURS' => [
				'START' => ['H' => 9, 'M' => 0, 'S' => 0],
				'END' => ['H' => 19, 'M' => 0, 'S' => 0],
			],
			'HOLIDAYS' => [],
			'WEEKEND' => ['SA', 'SU'],
			'WEEK_START' => 'MO',
			'SERVER_OFFSET' => (new \DateTime())->getOffset(),
		];
	}

	private function parseHolidays(string $raw): array
	{
		$holidays = [];

		foreach (explode(',', $raw) as $item)
		{
			$parts = explode('.', trim($item));
			$day = (int)($parts[0] ?? 0);
			$month = (int)($parts[1] ?? 0);

			if ($day > 0 && $month > 0)
			{
				$holidays[] = ['M' => $month, 'D' => $day];
			}
		}

		return $holidays;
	}

	private function parseTime(string $raw, array $default): array
	{
		if ($raw === '')
		{
			return $default;
		}

		$parts = explode('.', $raw);

		return [
			'H' => isset($parts[0]) ? (int)$parts[0] : $default['H'],
			'M' => isset($parts[1]) ? (int)$parts[1] : $default['M'],
			'S' => 0,
		];
	}
}
