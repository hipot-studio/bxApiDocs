<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Provider\Presence;

/**
 * The group of the work day of one employee on a date, the rule every row of the presence list is grouped by.
 *
 * The group is decided by the work day alone, the working groups are divided by the check-ins of the date,
 * and the marks weigh on nothing. On a past date an unclosed day is finished, with no end made up for it.
 */
final class PresenceGrouper
{
	/**
	 * A day without a start is not started, a closed day or any day of a date that is over is finished, and a day
	 * being worked goes by the check-ins of the date.
	 *
	 * @param int|null $startTs the start of the work day, null when it has not started
	 * @param bool $closed the day was closed
	 * @param bool $hasCheckIns the employee has check-ins on the date, with coordinates or without
	 * @param bool $isPastDate the date is over, so a day nobody closed is not being worked any more
	 */
	public static function groupOf(?int $startTs, bool $closed, bool $hasCheckIns, bool $isPastDate): PresenceGroup
	{
		if ($startTs === null)
		{
			return PresenceGroup::NotStarted;
		}

		if ($closed || $isPastDate)
		{
			return PresenceGroup::Finished;
		}

		return $hasCheckIns ? PresenceGroup::WorkingWithCheckIns : PresenceGroup::WorkingNoCheckIns;
	}
}
