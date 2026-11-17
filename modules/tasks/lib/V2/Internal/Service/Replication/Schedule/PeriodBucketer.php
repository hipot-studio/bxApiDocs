<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Replication\Schedule;

/**
 * Splits the schedule period into buckets of the chosen scale and assigns a timestamp
 * to a bucket - in the viewer's timezone (AC-S-021, AC-S-062).
 *
 * `tzOffset` is the offset in seconds that maps a server UTC timestamp to the viewer's local
 * time. Bucket bounds and fact/plan assignment are computed in the same timezone, otherwise
 * fact and plan land in different buckets (a "today"/segment bound desync, P2.T2). The bucket
 * key is the normalized segment start date (`Y-m-d`), formed via gmdate from the shifted time
 * so the server timezone does not interfere.
 */
final class PeriodBucketer
{
	public const DAY = 'day';
	public const WEEK = 'week';
	public const MONTH = 'month';
	public const YEAR = 'year';

	private const DAY_SECONDS = 86400;
	private const MAX_BUCKETS = 1000;

	public static function isValidScale(string $scale): bool
	{
		return in_array($scale, [self::DAY, self::WEEK, self::MONTH, self::YEAR], true);
	}

	/**
	 * Ordered list of bucket keys covering [from; to].
	 *
	 * @return string[]
	 */
	public function buildBucketKeys(string $scale, int $from, int $to, int $tzOffset): array
	{
		if (!self::isValidScale($scale) || $to < $from)
		{
			return [];
		}

		$keys = [];
		$cursor = $this->bucketStartLocalTs($scale, $from, $tzOffset);
		$end = $to + $tzOffset;

		$guard = 0;
		while ($cursor <= $end && $guard < self::MAX_BUCKETS)
		{
			$keys[] = $this->formatKey($cursor);
			$cursor = $this->nextBucketStart($scale, $cursor);
			$guard++;
		}

		return $keys;
	}

	/**
	 * Bucket key for a server timestamp (or null if the scale is invalid).
	 */
	public function bucketKeyForTimestamp(string $scale, int $serverTs, int $tzOffset): ?string
	{
		if (!self::isValidScale($scale))
		{
			return null;
		}

		return $this->formatKey($this->bucketStartLocalTs($scale, $serverTs, $tzOffset));
	}

	/**
	 * @return array{from:int, to:int}|null Server timestamps, inclusive start and exclusive end.
	 */
	public function getBucketRange(string $scale, string $key, int $tzOffset): ?array
	{
		if (!self::isValidScale($scale))
		{
			return null;
		}

		$localStart = strtotime($key . ' UTC');
		if (
			$localStart === false
			|| $this->formatKey($localStart) !== $key
			|| $this->bucketStartLocalTs($scale, $localStart, 0) !== $localStart
		)
		{
			return null;
		}

		return [
			'from' => $localStart - $tzOffset,
			'to' => $this->nextBucketStart($scale, $localStart) - $tzOffset,
		];
	}

	/**
	 * Whether the bucket with the given key contains "today" (in the viewer's timezone).
	 */
	public function isTodayKey(string $scale, string $key, int $tzOffset): bool
	{
		return $this->bucketKeyForTimestamp($scale, time(), $tzOffset) === $key;
	}

	/**
	 * The local (shifted) start time of the bucket that contains $serverTs.
	 */
	private function bucketStartLocalTs(string $scale, int $serverTs, int $tzOffset): int
	{
		$local = $serverTs + $tzOffset;

		$year = (int)gmdate('Y', $local);
		$month = (int)gmdate('n', $local);
		$day = (int)gmdate('j', $local);

		return match ($scale)
		{
			self::DAY => (int)gmmktime(0, 0, 0, $month, $day, $year),
			self::WEEK => $this->weekStart($local),
			self::MONTH => (int)gmmktime(0, 0, 0, $month, 1, $year),
			self::YEAR => (int)gmmktime(0, 0, 0, 1, 1, $year),
			default => $local,
		};
	}

	private function nextBucketStart(string $scale, int $bucketStartLocalTs): int
	{
		$year = (int)gmdate('Y', $bucketStartLocalTs);
		$month = (int)gmdate('n', $bucketStartLocalTs);
		$day = (int)gmdate('j', $bucketStartLocalTs);

		return match ($scale)
		{
			self::DAY => $bucketStartLocalTs + self::DAY_SECONDS,
			self::WEEK => $bucketStartLocalTs + 7 * self::DAY_SECONDS,
			self::MONTH => (int)gmmktime(0, 0, 0, $month + 1, 1, $year),
			self::YEAR => (int)gmmktime(0, 0, 0, 1, 1, $year + 1),
			default => $bucketStartLocalTs + self::DAY_SECONDS,
		};
	}

	/**
	 * The start of the ISO week (Monday) for a local time.
	 */
	private function weekStart(int $localTs): int
	{
		$year = (int)gmdate('Y', $localTs);
		$month = (int)gmdate('n', $localTs);
		$day = (int)gmdate('j', $localTs);
		$dayStart = (int)gmmktime(0, 0, 0, $month, $day, $year);

		$isoDow = (int)gmdate('N', $dayStart); // 1 (Mon) ... 7 (Sun)

		return $dayStart - ($isoDow - 1) * self::DAY_SECONDS;
	}

	private function formatKey(int $localBucketStartTs): string
	{
		return gmdate('Y-m-d', $localBucketStartTs);
	}
}
