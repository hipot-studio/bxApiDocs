<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Metrics;

/**
 * Buckets of the response time of a call of the sync contour. A bucket is named by its upper bound,
 * which it includes; everything slower than the last bound falls into the open bucket.
 */
final class LatencyBucket
{
	public const OPEN = -1;

	public const BOUNDS_MS = [5, 10, 15, 25, 35, 50, 75, 100, 250, 500, 1000, 2500, 5000];

	public static function of(float $ms): int
	{
		foreach (self::BOUNDS_MS as $bound)
		{
			if ($ms <= $bound)
			{
				return $bound;
			}
		}

		return self::OPEN;
	}
}
