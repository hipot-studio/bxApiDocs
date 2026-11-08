<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\ScheduledMessage;

use Bitrix\Mail\Internals\ScheduledMessageTable;
use Bitrix\Main\Context;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;

Loc::loadMessages(__FILE__);

/**
 * The moment a letter is to leave, as the row of the section says it, together with the mark of a letter that
 * is about to go. Both come out of one call because they come out of one decision: the case chosen for the
 * text is the same case that colours the row, and two calls could disagree about it. The moment the decision
 * was made on comes back with them, so a caller that also names it exactly names the same one.
 *
 * Which moment that is, is decided by one rule and by the rule of the dispatcher: see `dueAt()`.
 *
 * Days are counted on the calendar of the author of the schedule and not on the calendar of whoever is
 * reading: a letter set for tonight by an author in Los Angeles must not read as "tomorrow" to a colleague
 * in Moscow who shares the mailbox.
 */
final class ScheduledMessageTimePresenter
{
	/** One threshold for the wording and for the colour: below it the row reads as urgent and says so. */
	private const URGENT_WITHIN_SECONDS = 3600;

	private const WEEK_DAYS = 7;

	/** The section already reads a zone it cannot resolve as UTC; a letter with such a zone reads the same. */
	private const FALLBACK_TIMEZONE_ID = 'UTC';

	private function __construct(
		public readonly string $text,
		public readonly bool $isUrgent,
		/** The moment the wording was chosen by, so a caller naming it exactly names the same one. */
		public readonly DateTime $dueAt,
	)
	{
	}

	/**
	 * A page that words many rows resolves the zone of an author and the moment of reference once and hands
	 * them over: rows worded from moments of their own disagree with each other across the turn of a minute.
	 *
	 * @param \DateTimeZone|null $authorTimezone the zone of `$authorTimezoneId`, when the caller holds it
	 * @param DateTime|null $updatedAt the moment the row was last written, which says whether a send is running
	 */
	public static function present(
		DateTime $scheduledAt,
		?DateTime $nextAttemptAt,
		string $authorTimezoneId,
		string $status,
		?DateTime $now = null,
		?\DateTimeZone $authorTimezone = null,
		?DateTime $updatedAt = null,
	): self
	{
		$dueAt = self::dueAt($scheduledAt, $nextAttemptAt, $status);
		$reference = $now ?? new DateTime();
		$timezone = $authorTimezone ?? self::resolveTimezone($authorTimezoneId);
		$authorAt = self::inTimezone($dueAt, $timezone);
		$authorNow = self::inTimezone($reference, $timezone);
		$secondsLeft = $authorAt->getTimestamp() - $authorNow->getTimestamp();

		if (self::isLeaving($status, $updatedAt, $reference) && self::isAboutToLeave($secondsLeft))
		{
			return new self(self::renderMinutesLeft($secondsLeft), true, $dueAt);
		}

		$culture = Context::getCurrent()->getCulture();
		$time = self::formatMoment($authorAt, $culture->getShortTimeFormat());

		if (self::isSameDay($authorAt, $authorNow))
		{
			return new self(
				(string)Loc::getMessage('MAIL_SCHEDULED_TIME_TODAY', ['#TIME#' => $time]),
				false,
				$dueAt,
			);
		}

		if (self::isSameDay($authorAt, self::shiftDays($authorNow, 1)))
		{
			return new self(
				(string)Loc::getMessage('MAIL_SCHEDULED_TIME_TOMORROW', ['#TIME#' => $time]),
				false,
				$dueAt,
			);
		}

		if (
			$secondsLeft > 0
			&& $authorAt->getTimestamp() < self::shiftDays($authorNow, self::WEEK_DAYS)->getTimestamp()
		)
		{
			return new self(
				self::renderDatedMoment(
					'MAIL_SCHEDULED_TIME_WITHIN_WEEK',
					$authorAt,
					$culture->getShortDayOfWeekShortMonthFormat(),
					$time,
				),
				false,
				$dueAt,
			);
		}

		if ($authorAt->format('Y') === $authorNow->format('Y'))
		{
			return new self(
				self::renderDatedMoment(
					'MAIL_SCHEDULED_TIME_THIS_YEAR',
					$authorAt,
					$culture->getDayShortMonthFormat(),
					$time,
				),
				false,
				$dueAt,
			);
		}

		return new self(
			self::renderDatedMoment(
				'MAIL_SCHEDULED_TIME_OTHER_YEAR',
				$authorAt,
				$culture->getMediumDateFormat(),
				$time,
			),
			false,
			$dueAt,
		);
	}

	/**
	 * The moment a letter is to leave is the later of the two the dispatcher claims it by: `getDue()` asks for
	 * the moment of the plan and for the moment of the next attempt at once, and `isTooLate()` counts the delay
	 * from the later one. Read from the plan alone, the row would promise the next minute to a letter waiting
	 * out a backoff of an hour, and the old moment of the plan to a letter the author has just sent again by
	 * hand.
	 *
	 * A letter the dispatcher has given up on leaves at no moment at all, so its row keeps the moment it was
	 * planned for: that is what the author needs to read off a failure.
	 */
	private static function dueAt(DateTime $scheduledAt, ?DateTime $nextAttemptAt, string $status): DateTime
	{
		if ($nextAttemptAt === null || !self::isOnItsWay($status))
		{
			return $scheduledAt;
		}

		return $nextAttemptAt->getTimestamp() > $scheduledAt->getTimestamp() ? $nextAttemptAt : $scheduledAt;
	}

	/**
	 * The relative form promises a send, so it belongs to a letter that is still going to leave. A letter the
	 * dispatcher has given up on keeps the moment it was planned for, named in full: "in a minute" would
	 * promise a send that never comes, and the row would colour it as the nearest one of the section.
	 */
	private static function isOnItsWay(string $status): bool
	{
		return in_array(
			$status,
			[ScheduledMessageTable::STATUS_SCHEDULED, ScheduledMessageTable::STATUS_SENDING],
			true,
		);
	}

	/**
	 * The status of a send says a letter is leaving only while there is a send behind it. A send interrupted
	 * after the claim of the row leaves that status where it is, and the letter cannot go anywhere until
	 * `ScheduledMessageDispatcher::recoverStuckSending()` takes the row back — so past the border of that
	 * recovery the row names its moment in full instead of promising the next minute in the colour of the
	 * nearest letter of the section. The border is read off the dispatcher and not counted here a second time.
	 */
	private static function isLeaving(string $status, ?DateTime $updatedAt, DateTime $now): bool
	{
		if (!self::isOnItsWay($status))
		{
			return false;
		}

		if ($status !== ScheduledMessageTable::STATUS_SENDING || $updatedAt === null)
		{
			return true;
		}

		return $now->getTimestamp() - $updatedAt->getTimestamp()
			< ScheduledMessageDispatcher::STUCK_SENDING_TIMEOUT
		;
	}

	/**
	 * The status alone does not say whether a letter is going anywhere: the dispatcher gives up on a moment
	 * that passed longer ago than `ScheduledMessageDispatcher::MAX_DELAY_SECONDS` and reports the letter
	 * instead of sending it. The border is read off the dispatcher and not counted here a second time: a
	 * portal whose agents stood still keeps a whole section of such letters, and every row of it would
	 * otherwise promise a send within the minute.
	 */
	private static function isAboutToLeave(int $secondsLeft): bool
	{
		return $secondsLeft < self::URGENT_WITHIN_SECONDS
			&& $secondsLeft >= -ScheduledMessageDispatcher::MAX_DELAY_SECONDS
		;
	}

	/**
	 * The moment may already have passed while the letter waited to be claimed. A minute is the smallest thing
	 * the row says, so such a letter is named by the same phrase: it is leaving either way.
	 */
	private static function renderMinutesLeft(int $secondsLeft): string
	{
		$minutes = max(1, (int)floor($secondsLeft / 60));

		return (string)Loc::getMessagePlural('MAIL_SCHEDULED_TIME_IN_MINUTES', $minutes, ['#COUNT#' => $minutes]);
	}

	private static function renderDatedMoment(
		string $phrase,
		DateTime $authorAt,
		string $dateFormat,
		string $time,
	): string
	{
		return (string)Loc::getMessage($phrase, [
			'#DATE#' => self::formatMoment($authorAt, $dateFormat),
			'#TIME#' => $time,
		]);
	}

	/**
	 * Months and days of the week are named in the language of the portal only by `FormatDate()`, and it reads
	 * every part of the moment in the timezone of the process. The author may live in another one, so what is
	 * handed over is the moment that shows the wall clock of the author here.
	 */
	private static function formatMoment(DateTime $authorMoment, string $format): string
	{
		$wallClock = new DateTime($authorMoment->format('Y-m-d H:i:s'), 'Y-m-d H:i:s');

		return (string)\FormatDate($format, $wallClock->getTimestamp());
	}

	private static function inTimezone(DateTime $moment, \DateTimeZone $timezone): DateTime
	{
		$authorMoment = clone $moment;
		$authorMoment->setTimeZone($timezone);

		return $authorMoment;
	}

	private static function shiftDays(DateTime $moment, int $days): DateTime
	{
		$shifted = clone $moment;
		$shifted->add($days . 'D');

		return $shifted;
	}

	private static function isSameDay(DateTime $first, DateTime $second): bool
	{
		return $first->format('Y-m-d') === $second->format('Y-m-d');
	}

	private static function resolveTimezone(string $timezoneId): \DateTimeZone
	{
		try
		{
			return new \DateTimeZone($timezoneId !== '' ? $timezoneId : self::FALLBACK_TIMEZONE_ID);
		}
		catch (\Exception)
		{
			return new \DateTimeZone(self::FALLBACK_TIMEZONE_ID);
		}
	}
}
