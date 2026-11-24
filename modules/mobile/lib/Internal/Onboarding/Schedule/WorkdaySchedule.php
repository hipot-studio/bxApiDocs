<?php

namespace Bitrix\Mobile\Internal\Onboarding\Schedule;

use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Service\Marketing\Time\PortalCalendar;
use Bitrix\Mobile\Internal\Service\Marketing\Time\TimeWindow;

/**
 * Counts send dates as offsets from the series anchor. Pure mechanism: which days
 * count, whether offset 0 may land on a weekend and how long its send window lasts come
 * from the region descriptor (`schedule.workdaysOnly`, `schedule.shiftWeekendToMonday`,
 * `schedule.sendWindow`) through the constructor.
 *
 * A window and a calendar are what only a campaign document brings (`ScheduleFactory`):
 * without them offset 0 is returned as is even on a weekend and even when its window is
 * already over, and dates are read in the server zone - the behaviour of the legacy day map.
 */
class WorkdaySchedule implements ScheduleInterface
{
	private const SATURDAY = 6;
	private const SUNDAY = 0;
	private const WEEK_DAYS = 7;

	/**
	 * Absolute bound on the day-by-day walk, independent of the requested offset: a
	 * campaign document asking for an enormous offset must cost a bounded number of
	 * iterations per user, not one proportional to the value it declares.
	 */
	private const MAX_ITERATIONS = 120;

	public function __construct(
		private readonly bool $workdaysOnly = true,
		private readonly bool $shiftWeekendToMonday = false,
		private readonly ?TimeWindow $sendWindow = null,
		private readonly ?PortalCalendar $calendar = null,
	)
	{}

	/**
	 * Offsets above zero are counted from day 0 as it was actually placed, so neither the
	 * weekend shift nor a closed send window can collapse days 0 and 1 onto the same date
	 * and cost the series a send day.
	 */
	public function calculateSendDate(DateTime $anchorDate, int $offsetDays): DateTime
	{
		return $this->addSendDays($this->placeDayZero($this->toCalendarTime($anchorDate)), $offsetDays);
	}

	public function addSendDays(DateTime $date, int $sendDays): DateTime
	{
		$date = clone $date;
		if ($sendDays <= 0)
		{
			return $date;
		}

		$remaining = $sendDays;
		$guard = self::MAX_ITERATIONS;

		while ($remaining > 0 && $guard-- > 0)
		{
			$date->add('1 day');
			if ($this->isValidSendDay($date))
			{
				$remaining--;
			}
		}

		return $date;
	}

	public function isValidSendDay(DateTime $date): bool
	{
		if (!$this->workdaysOnly)
		{
			return true;
		}

		$dayOfWeek = (int)$date->format('w');

		return $dayOfWeek !== self::SATURDAY && $dayOfWeek !== self::SUNDAY;
	}

	/**
	 * Date of offset 0. The anchor day itself, unless its send window is already over: a
	 * portal created after the window closes - 13:00, and a Friday evening above all
	 * - would otherwise get no first push at all, and the series would
	 * lose a send day.
	 *
	 * Whether the day it lands on may be a weekend is the business of shiftWeekendToMonday
	 * alone, and of both cases equally: a closed window moves the anchor one day on, and the
	 * flag then decides the same way it decides for an anchor whose window is still open. The
	 * two used to disagree, so a document leaving the flag at its default placed a Saturday
	 * portal on Saturday in the morning and on Monday in the afternoon.
	 */
	private function placeDayZero(DateTime $date): DateTime
	{
		if ($this->sendWindow?->isOverAt($date) === true)
		{
			$date->add('1 day');
		}

		return $this->shiftWeekendToMonday ? $this->shiftToSendDay($date) : $date;
	}

	private function toCalendarTime(DateTime $date): DateTime
	{
		return $this->calendar === null ? clone $date : $this->calendar->toCalendarTime($date);
	}

	/**
	 * Nearest send day at or after the given date.
	 */
	private function shiftToSendDay(DateTime $date): DateTime
	{
		$guard = self::WEEK_DAYS;

		while (!$this->isValidSendDay($date) && $guard-- > 0)
		{
			$date->add('1 day');
		}

		return $date;
	}
}
