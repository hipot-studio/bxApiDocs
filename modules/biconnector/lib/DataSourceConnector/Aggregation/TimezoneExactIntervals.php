<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

/**
 * Date intervals in which a day-aligned `dateRange` predicate is exact (design §5.11.2, §6.8).
 *
 * A timezone conversion shifts wall-clock values, so a day boundary of the portal predicate matches
 * the one Trino sees only inside a stretch without offset changes. The capability therefore publishes
 * every such stretch, and the plugin delegates a predicate only when its whole span sits inside one of
 * them; transition days themselves are excluded conservatively.
 *
 * The list is a function of the timezone database alone and never of the current time: making it
 * depend on today would change the capabilities fingerprint twice a year and invalidate the plugin
 * cache on every transition, and the two sides would disagree whenever their clocks differ.
 */
final class TimezoneExactIntervals
{
	/** Lower sentinel: no source predicate reaches it, so it stands for "no earlier boundary". */
	public const SENTINEL_FROM = '1000-01-01';

	/** Upper sentinel; design §5.11.1 (г) additionally forbids treating it as an exact upper bound. */
	public const SENTINEL_TO = '9999-12-31';

	/**
	 * Fixed scan window. Both ends are constants rather than offsets from today, which is what keeps the
	 * answer identical on both sides and stable over time. A zone that keeps changing its offset past the
	 * horizon gets no interval beyond it, so a query out there is simply not exact.
	 *
	 * The scan starts at the lower sentinel and not at the epoch: the first interval is published as
	 * starting there, and Europe/Moscow alone has fifteen transitions before 1970 - claiming that whole
	 * stretch exact without looking at it would hand the client a range with real offset changes inside.
	 */
	private const SCAN_FROM = self::SENTINEL_FROM;
	private const SCAN_TO = '2100-01-01';

	/** Window used only to tell a still-recurring zone from one that has settled for good. */
	private const RECURRENCE_PROBE_TO = '2105-01-01';

	private function __construct()
	{
	}

	/**
	 * @param string|null $timezone Timezone name, or null when no conversion is applied at all.
	 *
	 * @return array<int, array{from: string, to: string}>
	 */
	public static function forTimezone(?string $timezone): array
	{
		if ($timezone === null || $timezone === '')
		{
			// Without a conversion the wall clock never shifts, so every day boundary is exact.
			return [self::interval(self::SENTINEL_FROM, self::SENTINEL_TO)];
		}

		try
		{
			$zone = new \DateTimeZone($timezone);
		}
		catch (\Exception)
		{
			// An unusable zone name proves nothing; claiming exactness would be a guess.
			return [];
		}

		$transitionDays = self::getTransitionDays($zone, self::SCAN_FROM, self::SCAN_TO);
		if ($transitionDays === [])
		{
			return [self::interval(self::SENTINEL_FROM, self::SENTINEL_TO)];
		}

		$intervals = [];
		$from = self::SENTINEL_FROM;
		foreach ($transitionDays as $day)
		{
			$dayBefore = self::shiftDay($day, -1);
			if ($dayBefore >= $from)
			{
				$intervals[] = self::interval($from, $dayBefore);
			}

			$from = self::shiftDay($day, 1);
		}

		$intervals[] = self::interval($from, self::getTailBoundary($zone));

		return $intervals;
	}

	/**
	 * A zone that still changes its offset after the horizon cannot claim the upper sentinel: the tail
	 * stops at the horizon and anything beyond it stays inexact.
	 */
	private static function getTailBoundary(\DateTimeZone $zone): string
	{
		$recurring = self::getTransitionDays($zone, self::SCAN_TO, self::RECURRENCE_PROBE_TO) !== [];

		return $recurring ? self::shiftDay(self::SCAN_TO, -1) : self::SENTINEL_TO;
	}

	/**
	 * @return string[] Distinct transition dates in ascending order.
	 */
	private static function getTransitionDays(\DateTimeZone $zone, string $from, string $to): array
	{
		$transitions = $zone->getTransitions(self::timestamp($from), self::timestamp($to));
		if (!is_array($transitions))
		{
			return [];
		}

		$days = [];
		foreach ($transitions as $index => $transition)
		{
			// The first entry describes the state at the start of the window and is not a transition.
			if ($index === 0 || !isset($transition['time']))
			{
				continue;
			}

			$days[substr((string)$transition['time'], 0, 10)] = true;
		}

		$days = array_keys($days);
		sort($days);

		return $days;
	}

	private static function timestamp(string $date): int
	{
		return (new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC')))->getTimestamp();
	}

	private static function shiftDay(string $date, int $days): string
	{
		$shifted = (new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC')))
			->modify(($days >= 0 ? '+' : '-') . abs($days) . ' day')
		;

		return $shifted->format('Y-m-d');
	}

	/**
	 * @return array{from: string, to: string}
	 */
	private static function interval(string $from, string $to): array
	{
		return ['from' => $from, 'to' => $to];
	}
}
