<?php

namespace Bitrix\Mobile\Internal\Onboarding\Schedule;

use Bitrix\Main\Type\DateTime;

/**
 * Daily send window of a region as its configuration declares it (`schedule.sendWindow`
 * start and length). Answers one question for the schedule: is the window of a given day
 * already over, so the day can no longer carry a push.
 */
final readonly class SendWindow
{
	private const MINUTES_PER_HOUR = 60;
	private const MINUTES_PER_DAY = 24 * self::MINUTES_PER_HOUR;

	private function __construct(
		private int $startMinuteOfDay,
		private int $lengthMinutes,
	)
	{}

	/**
	 * Null when the configuration declares no usable window: an unparsable start, a zero-length
	 * window or one that would run into the next day says nothing about a day, so no date may be
	 * moved because of it.
	 *
	 * A window is a statement about one day - both questions below are answered by the minute of
	 * that day - and one running past midnight cannot be made into that statement: contains()
	 * would close it at 23:59 instead of wrapping, and isOverAt() would never once be true, so
	 * day zero would stay where it stands whatever the hour. Refusing it keeps the failure loud
	 * and in one place, at the acceptance of the document.
	 */
	public static function tryFrom(string $start, int $lengthHours): ?self
	{
		if ($lengthHours <= 0 || !preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $start, $matches))
		{
			return null;
		}

		$startMinuteOfDay = (int)$matches[1] * self::MINUTES_PER_HOUR + (int)$matches[2];
		$lengthMinutes = $lengthHours * self::MINUTES_PER_HOUR;

		if ($startMinuteOfDay + $lengthMinutes > self::MINUTES_PER_DAY)
		{
			return null;
		}

		return new self($startMinuteOfDay, $lengthMinutes);
	}

	/**
	 * The moment must be expressed in the calendar the window is declared in.
	 */
	public function isOverAt(DateTime $moment): bool
	{
		return self::minuteOfDay($moment) >= $this->startMinuteOfDay + $this->lengthMinutes;
	}

	/**
	 * Whether the window holds the moment: its start counts, its end does not. The moment must
	 * be expressed in the calendar the window is declared in, as for isOverAt().
	 *
	 * Both questions are answered by the minute of the day rather than by seconds of the epoch,
	 * because that is what the configuration declares: "10:00 for two hours" is a statement
	 * about the clock on the wall. Seconds and the wall clock part company on the day a
	 * timezone shifts - and the two questions would then disagree about the same day, one
	 * deciding which day a push belongs to and the other whether it may go out now.
	 */
	public function contains(DateTime $moment): bool
	{
		$minuteOfDay = self::minuteOfDay($moment);

		return $minuteOfDay >= $this->startMinuteOfDay
			&& $minuteOfDay < $this->startMinuteOfDay + $this->lengthMinutes
		;
	}

	/**
	 * The start of the window on the day of $day, in the calendar the window is declared in.
	 * That hour is inside every window by definition, which is what a run needing an hour the
	 * window accepts is placed at. The given day is left as it was.
	 */
	public function startOn(DateTime $day): DateTime
	{
		return (clone $day)->setTime(
			intdiv($this->startMinuteOfDay, self::MINUTES_PER_HOUR),
			$this->startMinuteOfDay % self::MINUTES_PER_HOUR,
			0,
		);
	}

	private static function minuteOfDay(DateTime $moment): int
	{
		return (int)$moment->format('H') * self::MINUTES_PER_HOUR + (int)$moment->format('i');
	}
}
