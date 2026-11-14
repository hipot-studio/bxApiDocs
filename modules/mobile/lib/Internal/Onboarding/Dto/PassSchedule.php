<?php

namespace Bitrix\Mobile\Internal\Onboarding\Dto;

use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Onboarding\Schedule\ScheduleInterface;

/**
 * Send day of the series for one user, from the workday arithmetic of the region.
 *
 * $scheduledDate is the day a push of the series may go out on, null when the series has
 * nothing for today. $scheduledDay is the offset of that day in the day map of a legacy
 * region; a campaign region has no day map - it counts every send from the push that
 * actually went out before it - so the offset there is null while the date is not.
 *
 * $anchorDate stays the moment it was built from - the activation checkers read it as a
 * threshold, not as a calendar date.
 */
final readonly class PassSchedule
{
	public function __construct(
		public DateTime $anchorDate,
		public ScheduleInterface $schedule,
		public ?DateTime $scheduledDate,
		public ?int $scheduledDay = null,
	)
	{}

	/**
	 * Schedule of a region whose series is a list of absolute offsets from the anchor: the
	 * date of the send day follows from the offset, and no offset today means no send day.
	 */
	public static function fromDayOffset(
		DateTime $anchorDate,
		ScheduleInterface $schedule,
		?int $scheduledDay,
	): self
	{
		return new self(
			$anchorDate,
			$schedule,
			$scheduledDay === null ? null : $schedule->calculateSendDate($anchorDate, $scheduledDay),
			$scheduledDay,
		);
	}
}
