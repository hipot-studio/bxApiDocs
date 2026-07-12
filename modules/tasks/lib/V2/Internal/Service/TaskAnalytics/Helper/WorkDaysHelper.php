<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\TaskAnalytics\Helper;

use Bitrix\Tasks\Util\Calendar;
use Bitrix\Tasks\Util\Type\DateTime;

class WorkDaysHelper
{
	private const SECONDS_IN_DAY = 60 * 60 * 24;
	private const SECONDS_IN_YEAR = 365 * self::SECONDS_IN_DAY;

	private readonly Calendar $calendar;
	private array $isWorkDayCache = [];

	public function __construct()
	{
		$this->calendar = Calendar::getInstance();
	}

	/**
	 * Returns the number of working calendar days between two moments normalized to the start of day
	 *
	 * calculateBetween(Monday 09:00, Friday 18:00)  = 4  (Mon - 0, Tue - 1, Wed - 2, Thu - 3, Fri - 4)
	 * calculateBetween(Monday 23:00, Tuesday 01:00) = 1  (Monday is over)
	 * calculateBetween(today 09:00, today 18:00)    = 0
	 */
	public function calculateBetween(?int $fromTs, ?int $toTs, ?int $countingLimit = self::SECONDS_IN_YEAR): ?int
	{
		if (!$this->checkTimestamps($fromTs, $toTs))
		{
			return null;
		}

		$fromDay = $this->startOfDay($fromTs);
		$toDay = $this->startOfDay($toTs);

		if ($toDay <= $fromDay)
		{
			return 0;
		}

		if ($countingLimit !== null && $toDay > ($fromDay + $countingLimit))
		{
			$toDay = $fromDay + $countingLimit;
		}

		$days = 0;
		for ($cursor = $fromDay; $cursor < $toDay; $cursor += self::SECONDS_IN_DAY)
		{
			if ($this->isWorkDay($cursor))
			{
				$days++;
			}
		}

		return $days;
	}

	private function checkTimestamps(?int $fromTs, ?int $toTs): bool
	{
		if ($fromTs === null || $fromTs <= 0)
		{
			return false;
		}

		if ($toTs === null || $toTs <= 0)
		{
			return false;
		}

		if ($toTs < $fromTs)
		{
			return false;
		}

		return true;
	}

	private function startOfDay(int $ts): int
	{
		return $ts - ($ts % self::SECONDS_IN_DAY);
	}

	private function isWorkDay(int $ts): bool
	{
		$dayKey = intdiv($ts, self::SECONDS_IN_DAY);

		if (isset($this->isWorkDayCache[$dayKey]))
		{
			return $this->isWorkDayCache[$dayKey];
		}

		$date = DateTime::createFromTimestamp($ts);

		$this->isWorkDayCache[$dayKey] = !$this->calendar->isWeekend($date) && !$this->calendar->isHoliday($date);

		return $this->isWorkDayCache[$dayKey];
	}
}
