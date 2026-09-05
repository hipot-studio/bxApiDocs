<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Mailbox;

/**
 * Single source of the sync period lower boundary: "the whole Nth day back is included",
 * counted from the start of the current UTC day. Every consumer of the tariff/period limit
 * (saving the mailbox period, the runtime sync cutoff, the old messages cleanup) must derive
 * the boundary here, so a mailbox saved with period N is never cut stricter at runtime
 * within the same day.
 */
final class SyncPeriodBoundary
{
	private const SECONDS_PER_DAY = 86400;

	public static function dayStartUtcMinusDays(int $days): int
	{
		// UTC days have no DST shifts, so plain arithmetic is exact
		$currentUtcDayStart = intdiv(time(), self::SECONDS_PER_DAY) * self::SECONDS_PER_DAY;

		return $currentUtcDayStart - $days * self::SECONDS_PER_DAY;
	}
}
