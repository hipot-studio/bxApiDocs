<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest;

/**
 * Monotonic time of the digest waits on Superset, in seconds. Taken from the service locator, so a case can
 * stand in for it instead of waiting out a deadline.
 */
class DigestClock
{
	public function now(): float
	{
		return hrtime(true) / 1e9;
	}
}
