<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Integration\Timeman;

/**
 * The work day of one employee on one date, as the presence list reads it.
 *
 * `trusted` is false when the records could not be read or the zone of the employee did not resolve;
 * `truncated` tells a read that hit the page limit from other untrusted ones, for analytics only. `paused`
 * is set on today only.
 *
 * The index of the presence list is kept for a minute with it as it is: a property added, dropped or retyped is a
 * new shape of its entry and bumps {@see \Bitrix\StaffTrack\Public\Provider\Presence\PresenceIndexCache::VERSION}.
 */
final class DayStateDto
{
	public function __construct(
		public readonly bool $trusted,
		public readonly bool $started,
		public readonly bool $closed,
		public readonly bool $paused,
		public readonly ?int $startTs = null,
		public readonly ?int $endTs = null,
		public readonly bool $truncated = false,
	)
	{
	}

	public static function untrusted(): self
	{
		return new self(trusted: false, started: false, closed: false, paused: false);
	}

	/**
	 * The page of records filled up, so the earliest shifts of the chunk were never seen - and the earliest
	 * shift is where the day begins. Untrusted like any unanswered read, and named apart from it.
	 */
	public static function truncated(): self
	{
		return new self(trusted: false, started: false, closed: false, paused: false, truncated: true);
	}

	public static function notStarted(): self
	{
		return new self(trusted: true, started: false, closed: false, paused: false);
	}
}
