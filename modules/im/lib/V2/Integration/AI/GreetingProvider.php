<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

final class GreetingProvider
{
	// Date::getDay() convention: 0 = Sunday .. 6 = Saturday
	private const DEFAULT_WEEK_HOLIDAYS = [6, 0];

	/**
	 * Greeting groups: [firstId, lastId, timeRange, days].
	 * Marking is strict and mutually exclusive by type:
	 * time windows carry timeRange with empty days, day-tagged carry days with null timeRange,
	 * easter eggs carry neither.
	 */
	private const GROUPS = [
		[1, 7, 'morning', []],
		[8, 13, 'day', []],
		[14, 18, 'evening', []],
		[19, 21, 'night', []],
		[22, 23, null, ['mon']],
		[24, 25, null, ['fri']],
		[26, 27, null, ['weekend']],
		[28, 30, null, []],
	];

	/**
	 * Returns the localized, tagged greeting pool for the empty BitrixGPT chat screen.
	 * The provider only shapes the data; phrase/time selection happens on the client.
	 *
	 * @return list<array{id: int, text: string, timeRange: ?string, days: list<string>}>
	 */
	public static function getList(): array
	{
		if (!Features::get()->isBitrixGptV2Available)
		{
			return [];
		}

		$pool = [];
		foreach (self::GROUPS as [$firstId, $lastId, $timeRange, $days])
		{
			for ($id = $firstId; $id <= $lastId; $id++)
			{
				$pool[] = [
					'id' => $id,
					'text' => (string)Loc::getMessage('IM_COPILOT_GREETING_PHRASE_' . $id),
					'timeRange' => $timeRange,
					'days' => $days,
				];
			}
		}

		return $pool;
	}

	/**
	 * Weekdays the portal treats as non-working, so the client tags them as weekend
	 * by the portal calendar instead of a hardcoded Saturday and Sunday.
	 *
	 * @return list<int> weekday indexes in Date::getDay() convention
	 */
	public static function getWeekHolidays(): array
	{
		if (!Loader::includeModule('calendar'))
		{
			return self::DEFAULT_WEEK_HOLIDAYS;
		}

		$holidays = \CCalendar::GetSettings()['week_holidays'] ?? [];
		if (!is_array($holidays) || empty($holidays))
		{
			return self::DEFAULT_WEEK_HOLIDAYS;
		}

		$indexes = array_map(static fn(string $weekday): int => \CCalendar::IndByWeekDay($weekday), $holidays);

		return array_values(array_unique($indexes));
	}
}
