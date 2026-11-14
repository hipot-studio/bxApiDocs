<?php

namespace Bitrix\Mobile\Internal\Onboarding\Schedule;

use Bitrix\Main\Type\DateTime;

/**
 * When the next push of the activation series is due.
 *
 * The series has no calendar of its own. Its first push sits on the anchor day of the
 * portal, and every following one is counted from the push that actually went out before
 * it - so the length of the series is decided by what the user does, not by how many days
 * have passed. A step completed on the last allowed attempt moves the series on; a step
 * left uncompleted with its attempts spent stops it (see OnboardingService).
 *
 * The first repeat comes sooner than the rest: the series nudges once quickly, then settles
 * into its slower rhythm.
 */
final readonly class SeriesRhythm
{
	public const DEFAULT_FIRST_REPEAT_WORKDAYS = 1;
	public const DEFAULT_REPEAT_WORKDAYS = 2;

	/** An interval no document may exceed, so a typo cannot park the series for a year. */
	public const MAX_INTERVAL_WORKDAYS = 30;

	public function __construct(
		public int $firstRepeatWorkdays = self::DEFAULT_FIRST_REPEAT_WORKDAYS,
		public int $repeatWorkdays = self::DEFAULT_REPEAT_WORKDAYS,
	)
	{}

	/**
	 * The rhythm the document declares (`schedule.repeat`), or null when it declares none.
	 * A node that is present but unusable is null as well: the acceptance of the document
	 * rejects it rather than letting the series run on a guessed rhythm.
	 */
	public static function fromNode(mixed $node): ?self
	{
		if (!is_array($node))
		{
			return null;
		}

		$first = $node['firstWorkdays'] ?? null;
		$then = $node['thenWorkdays'] ?? null;
		if (!self::isUsableInterval($first) || !self::isUsableInterval($then))
		{
			return null;
		}

		return new self((int)$first, (int)$then);
	}

	/**
	 * An interval is a whole number of send days between one and the ceiling: zero would
	 * put two pushes of the series on one day, and a fractional value means the author
	 * counted in something other than send days.
	 */
	private static function isUsableInterval(mixed $value): bool
	{
		return is_int($value) && $value >= 1 && $value <= self::MAX_INTERVAL_WORKDAYS;
	}

	/**
	 * Date the next send of the series is due for a user who has had $sendsSoFar of its
	 * pushes, the last of them on $lastSentDate.
	 *
	 * Nothing sent yet puts the send on the anchor day as the schedule places it - a closed
	 * window or a weekend move that day, and the series must not lose its first push to
	 * either. Everything after is counted from the last send, which is why the age of the
	 * portal never enters this: a user who came late walks the same rhythm from their own
	 * first push.
	 */
	public function dueDate(
		ScheduleInterface $schedule,
		DateTime $anchorDate,
		int $sendsSoFar,
		?DateTime $lastSentDate,
	): DateTime
	{
		if ($sendsSoFar <= 0 || $lastSentDate === null)
		{
			return $schedule->calculateSendDate($anchorDate, 0);
		}

		return $schedule->addSendDays($lastSentDate, $this->intervalAfter($sendsSoFar));
	}

	/**
	 * Send days between the send that already happened and the next one.
	 */
	public function intervalAfter(int $sendsSoFar): int
	{
		return $sendsSoFar <= 1 ? $this->firstRepeatWorkdays : $this->repeatWorkdays;
	}
}
