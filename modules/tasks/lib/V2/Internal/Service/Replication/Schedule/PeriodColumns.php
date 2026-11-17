<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Replication\Schedule;

/**
 * PER-01: resolves `period` (the section UI switch) into `(bucketer scale, displayed
 * bucket window)` and builds ordered period-column segments (P1.T2, AC-S-020/021).
 *
 * A single source of the split for both the column set ({@see \Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Column\DataProvider\ReplicationProvider}),
 * and the projection/aggregate ({@see ScheduleProjectionService}, {@see LoadAggregateService}):
 * segment keys match the projection bucket keys - so the cell frontend overlay finds its
 * data by `data-bucket-key`.
 *
 * Quarter is NOT introduced as a separate bucketer scale: quarter = `month` scale, a 3-month window.
 * The calendar period is aligned to the anchor and expanded to complete buckets by
 * {@see PeriodBucketer} (Monday of the week, the 1st of the month, etc.).
 */
final class PeriodColumns
{
	public const WEEK = 'week';
	public const MONTH = 'month';
	public const QUARTER = 'quarter';
	public const YEAR = 'year';

	// Short nominative month names (for the month-name label: quarter/year).
	private const MONTHS_SHORT = [
		1 => 'янв', 2 => 'фев', 3 => 'мар', 4 => 'апр', 5 => 'май', 6 => 'июн',
		7 => 'июл', 8 => 'авг', 9 => 'сен', 10 => 'окт', 11 => 'ноя', 12 => 'дек',
	];

	// Short genitive month names (used with a day number in the day and week segment labels).
	private const MONTHS_GEN_SHORT = [
		1 => 'янв', 2 => 'фев', 3 => 'мар', 4 => 'апр', 5 => 'мая', 6 => 'июн',
		7 => 'июл', 8 => 'авг', 9 => 'сен', 10 => 'окт', 11 => 'ноя', 12 => 'дек',
	];

	private const WEEKDAYS_SHORT = [
		1 => 'пн', 2 => 'вт', 3 => 'ср', 4 => 'чт', 5 => 'пт', 6 => 'сб', 7 => 'вс',
	];

	private const WEEK_SECONDS = 7 * 86400;

	// en dash with spaces - the range dash of the window label (period-label.js)
	private const RANGE_DASH = ' – ';

	public function __construct(
		private readonly PeriodBucketer $bucketer = new PeriodBucketer(),
	)
	{
	}

	public static function isValidPeriod(string $period): bool
	{
		return in_array($period, [self::WEEK, self::MONTH, self::QUARTER, self::YEAR], true);
	}

	/**
	 * The bucketer scale and the full displayed bucket window [from; to] in server UTC
	 * for the given period and anchor (in the viewer's timezone).
	 *
	 * @return array{scale: string, from: int, to: int}
	 */
	public function resolve(string $period, int $anchorTs, int $tzOffset): array
	{
		$window = $this->resolveCalendarWindow($period, $anchorTs, $tzOffset);
		$keys = $this->bucketer->buildBucketKeys(
			$window['scale'],
			$window['from'],
			$window['to'],
			$tzOffset,
		);
		if ($keys === [])
		{
			return $window;
		}

		$firstBucket = $this->bucketer->getBucketRange($window['scale'], $keys[0], $tzOffset);
		$lastBucket = $this->bucketer->getBucketRange(
			$window['scale'],
			$keys[array_key_last($keys)],
			$tzOffset,
		);
		if ($firstBucket === null || $lastBucket === null)
		{
			return $window;
		}

		return [
			'scale' => $window['scale'],
			'from' => $firstBucket['from'],
			'to' => $lastBucket['to'] - 1,
		];
	}

	/**
	 * DTO-W1: calendar window metadata for frontend navigation.
	 *
	 * @return array{from: int, to: int, isCurrent: bool}
	 */
	public function resolveWindowMeta(string $period, int $anchorTs, int $tzOffset): array
	{
		$window = $this->resolveCalendarWindow($period, $anchorTs, $tzOffset);

		$from = $window['from'];
		$to = $window['to'] + 86400;
		$now = time();

		return [
			'from' => $from,
			'to' => $to,
			'isCurrent' => $from <= $now && $now < $to,
		];
	}

	/**
	 * Ordered period-column segments: `{key, label, sub, isNow}`. Keys and bounds come
	 * from {@see PeriodBucketer::buildBucketKeys()} - the same split as the projection.
	 * Labels are the formatted bucket start date in the viewer's timezone.
	 *
	 * @return array<int, array{key: string, label: string, sub: string, isNow: bool}>
	 */
	public function build(string $period, int $anchorTs, int $tzOffset): array
	{
		if (!self::isValidPeriod($period))
		{
			return [];
		}

		$window = $this->resolve($period, $anchorTs, $tzOffset);
		$scale = $window['scale'];

		$keys = $this->bucketer->buildBucketKeys($scale, $window['from'], $window['to'], $tzOffset);
		$todayKey = $this->bucketer->bucketKeyForTimestamp($scale, time(), $tzOffset);

		$segments = [];
		foreach ($keys as $key)
		{
			$segments[] = [
				'key' => $key,
				'label' => $this->label($period, $key),
				'sub' => $this->sub($period, $key),
				'isNow' => ($key === $todayKey),
			];
		}

		return $segments;
	}

	/**
	 * @return array{scale: string, from: int, to: int}
	 */
	private function resolveCalendarWindow(string $period, int $anchorTs, int $tzOffset): array
	{
		$local = $anchorTs + $tzOffset;

		$year = (int)gmdate('Y', $local);
		$month = (int)gmdate('n', $local);
		$day = (int)gmdate('j', $local);

		[$scale, $fromLocal, $toLocal] = match ($period)
		{
			self::MONTH => [
				PeriodBucketer::WEEK,
				(int)gmmktime(0, 0, 0, $month, 1, $year),
				(int)gmmktime(0, 0, 0, $month + 1, 0, $year),
			],
			self::QUARTER => $this->quarterWindow($year, $month),
			self::YEAR => [
				PeriodBucketer::MONTH,
				(int)gmmktime(0, 0, 0, 1, 1, $year),
				(int)gmmktime(0, 0, 0, 12, 31, $year),
			],
			default => [
				PeriodBucketer::DAY,
				$this->mondayOf($year, $month, $day),
				$this->mondayOf($year, $month, $day) + 6 * 86400,
			],
		};

		return [
			'scale' => $scale,
			'from' => $fromLocal - $tzOffset,
			'to' => $toLocal - $tzOffset,
		];
	}

	/**
	 * @return array{0: string, 1: int, 2: int}
	 */
	private function quarterWindow(int $year, int $month): array
	{
		$firstMonth = (int)(floor(($month - 1) / 3) * 3) + 1;

		return [
			PeriodBucketer::MONTH,
			(int)gmmktime(0, 0, 0, $firstMonth, 1, $year),
			(int)gmmktime(0, 0, 0, $firstMonth + 3, 0, $year),
		];
	}

	private function mondayOf(int $year, int $month, int $day): int
	{
		$dayStart = (int)gmmktime(0, 0, 0, $month, $day, $year);
		$isoDow = (int)gmdate('N', $dayStart); // 1 (Mon) ... 7 (Sun)

		return $dayStart - ($isoDow - 1) * 86400;
	}

	/**
	 * The main segment label (source of truth for the frontend header; Q-1 - the frontend
	 * builds `sub` via a thead overlay by period + key): weekday -> day number with a short
	 * month, week -> its bounds, month -> short month name.
	 */
	private function label(string $period, string $key): string
	{
		$ts = $this->keyToTs($key);

		return match ($period)
		{
			self::WEEK => $this->dayWithMonth($ts),
			self::MONTH => $this->weekBoundsLabel($ts),
			default => self::MONTHS_SHORT[(int)gmdate('n', $ts)] ?? '',
		};
	}

	/**
	 * Month view: a week segment is named by its bounds - the start of the week and the start of
	 * the next one ("27 июл – 3 авг"), so a column reads as a period and not as a single date.
	 */
	private function weekBoundsLabel(int $ts): string
	{
		return $this->dayWithMonth($ts) . self::RANGE_DASH . $this->dayWithMonth($ts + self::WEEK_SECONDS);
	}

	private function dayWithMonth(int $ts): string
	{
		return (int)gmdate('j', $ts) . ' ' . (self::MONTHS_GEN_SHORT[(int)gmdate('n', $ts)] ?? '');
	}

	/**
	 * The auxiliary segment label: weekday (week), "week" abbreviation (month), 2-digit
	 * year (quarter), empty (year).
	 */
	private function sub(string $period, string $key): string
	{
		$ts = $this->keyToTs($key);

		return match ($period)
		{
			self::WEEK => self::WEEKDAYS_SHORT[(int)gmdate('N', $ts)] ?? '',
			self::MONTH => 'нед.',
			self::QUARTER => gmdate('y', $ts),
			default => '',
		};
	}

	private function keyToTs(string $key): int
	{
		return (int)strtotime($key . ' UTC');
	}
}
