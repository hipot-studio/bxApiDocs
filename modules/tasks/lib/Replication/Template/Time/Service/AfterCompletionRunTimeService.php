<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Replication\Template\Time\Service;

use Bitrix\Tasks\Replication\Template\Enum\OffsetDayType;
use Bitrix\Tasks\Util\Calendar;

/**
 * Fire time of the after_completion one-shot (part of ALG-02).
 *
 * The next instance is created COMPLETION_DELAY days (calendar or workday) after the previous one
 * is completed, at the preferred HH:MM. For a zero delay it is the same day at HH:MM, or the next
 * day if the completion happened after HH:MM (AC-T-011 / AC-T-013). The day-type applies only when
 * the delay is greater than zero.
 *
 * Pure day arithmetic on server-local timestamps: the caller resolves the author's timezone into
 * {@see $preferredSecondsOfDay} (as ExecutionService does for the schedule strategy), so this stays
 * unit-testable without a portal.
 */
final class AfterCompletionRunTimeService
{
	public function __construct(private readonly Calendar $calendar = new Calendar())
	{
	}

	/**
	 * @param int $completionTs server-local completion moment (unix)
	 * @param int $completionDelayDays delay after completion, >= 0
	 * @param int $preferredSecondsOfDay preferred HH:MM as server seconds-of-day
	 * @param OffsetDayType $offsetDayType calendar / workday (used only when the delay is > 0)
	 * @return int server-local unix timestamp the one-shot must fire at
	 */
	public function calculate(
		int $completionTs,
		int $completionDelayDays,
		int $preferredSecondsOfDay,
		OffsetDayType $offsetDayType,
	): int
	{
		$completionDelayDays = max(0, $completionDelayDays);
		$todayMidnight = $this->midnightOf($completionTs);

		if ($completionDelayDays > 0)
		{
			$targetMidnight = $offsetDayType === OffsetDayType::Workday
				? $this->calendar->shiftWorkdays($todayMidnight, $completionDelayDays)
				: $this->addDays($todayMidnight, $completionDelayDays);

			return $targetMidnight + $preferredSecondsOfDay;
		}

		// Zero delay: same day at HH:MM; if already past it, the next day (AC-T-013).
		$runAt = $todayMidnight + $preferredSecondsOfDay;
		if ($runAt <= $completionTs)
		{
			$runAt = $this->addDays($todayMidnight, 1) + $preferredSecondsOfDay;
		}

		return $runAt;
	}

	private function midnightOf(int $ts): int
	{
		return (int)mktime(0, 0, 0, (int)date('n', $ts), (int)date('j', $ts), (int)date('Y', $ts));
	}

	private function addDays(int $midnightTs, int $days): int
	{
		return (int)mktime(
			0,
			0,
			0,
			(int)date('n', $midnightTs),
			(int)date('j', $midnightTs) + $days,
			(int)date('Y', $midnightTs),
		);
	}
}
