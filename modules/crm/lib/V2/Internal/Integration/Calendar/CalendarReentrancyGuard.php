<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Calendar;

/**
 * Suppression of the reverse direction of the calendar synchronization: while this hit is writing into the
 * calendar, the handlers carrying an edit of a calendar event back into an activity have to do nothing,
 * otherwise the two directions call each other in a loop (ALG-03, ADR registry row C9-GUARD).
 *
 * A DEPTH, NOT A FLAG, AND RELEASED IN A FINALLY. Legacy raises a boolean before writing and lowers it after
 * the write (crm_activity.php:7190, :7251, :7302, :7304), which fails twice: an exception on the way leaves
 * the guard raised for the rest of the hit, so the next edit coming from the calendar is swallowed silently,
 * and a nested write lowers the guard of the write around it as soon as the inner one finishes. A counter
 * released in a finally answers both: the suppression lasts exactly as long as the outermost write, and it
 * ends even when the write throws. This is the one place where the V2 behaviour is deliberately not the
 * legacy behaviour, and it is why no reset of the depth is offered: after every call, successful or not, the
 * depth is back where it was.
 *
 * WHY THE STATE IS STATIC. The suppression is a fact of the whole hit rather than of one object: the writer
 * and the handler that has to keep quiet never meet each other, and the handler holds nothing it could have
 * been given the guard through. Legacy states the same fact with a static of its own, which stays the carrier
 * of the legacy handlers until they learn to ask this class as well.
 *
 * @internal
 */
final class CalendarReentrancyGuard
{
	private static int $depth = 0;

	/**
	 * Runs the action with the reverse direction of the synchronization suppressed.
	 *
	 * @template T
	 * @param callable(): T $action
	 * @return T whatever the action returned.
	 */
	public static function runIgnoringCalendarEvents(callable $action): mixed
	{
		self::$depth++;

		try
		{
			return $action();
		}
		finally
		{
			self::$depth--;
		}
	}

	/**
	 * Whether a write into the calendar is running right now - that is, whether an edit arriving from the
	 * calendar is an echo of our own write and has to be ignored.
	 */
	public static function isIgnoring(): bool
	{
		return self::$depth > 0;
	}
}
