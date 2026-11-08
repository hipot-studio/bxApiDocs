<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Event;

use DateTimeImmutable;
use DateTimeZone;

final class WeeklyRecurrenceMembership
{
	private const DAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

	/** EXDATE is deliberately ignored when checking a repeated exclusion. */
	public static function contains(array $event, string $date): bool
	{
		$rawRule = $event['RRULE'] ?? '';
		$rule = \CCalendarEvent::ParseRRULE($rawRule);
		if (($rule['FREQ'] ?? null) !== 'WEEKLY')
		{
			return false;
		}
		$start = self::day($event['DATE_FROM'] ?? '');
		$target = self::day($date);
		if ($start === null || $target === null || $target < $start)
		{
			return false;
		}
		$until = empty($rule['UNTIL']) ? null : self::day($rule['UNTIL']);
		if ($until !== null && $target > $until)
		{
			return false;
		}

		$weekStart = is_array($rawRule) ? ($rawRule['WKST'] ?? 'MO') : 'MO';
		if (is_string($rawRule) && preg_match('/(?:^|;)WKST=(MO|TU|WE|TH|FR|SA|SU)(?:;|$)/', $rawRule, $match))
		{
			$weekStart = $match[1];
		}
		$weekStartIndex = array_search($weekStart, self::DAYS, true);
		$weekStartIndex = $weekStartIndex === false ? 0 : $weekStartIndex;
		$startOffset = ((int)$start->format('N') - 1 - $weekStartIndex + 7) % 7;
		$targetOffset = ((int)$target->format('N') - 1 - $weekStartIndex + 7) % 7;
		$daysSinceStart = (int)$start->diff($target)->days;
		$week = intdiv($daysSinceStart + $startOffset, 7);
		$interval = max(1, (int)($rule['INTERVAL'] ?? 1));
		if ($week % $interval !== 0)
		{
			return false;
		}

		$byDay = \CCalendar::normalizeRRuleByDay($rule['BYDAY'] ?? null);
		if ($byDay === [])
		{
			$byDay = [self::DAYS[(int)$start->format('N') - 1]];
		}
		$offsets = [];
		foreach ($byDay as $day)
		{
			$index = array_search($day, self::DAYS, true);
			if ($index !== false)
			{
				$offsets[] = ($index - $weekStartIndex + 7) % 7;
			}
		}
		$offsets = array_values(array_unique($offsets));
		if (!in_array($targetOffset, $offsets, true))
		{
			return false;
		}
		if (!isset($rule['COUNT']))
		{
			return true;
		}

		$firstWeek = array_filter($offsets, static fn(int $offset): bool => $offset >= $startOffset);
		$activeWeek = intdiv($week, $interval);
		$currentWeek = $activeWeek === 0 ? $firstWeek : $offsets;
		$ordinal = count(array_filter($currentWeek, static fn(int $offset): bool => $offset <= $targetOffset));
		if ($activeWeek > 0)
		{
			$ordinal += count($firstWeek) + ($activeWeek - 1) * count($offsets);
		}

		return $ordinal <= (int)$rule['COUNT'];
	}

	private static function day(string $value): ?DateTimeImmutable
	{
		$timestamp = \CCalendar::Timestamp($value, false, false);
		if (!$timestamp)
		{
			return null;
		}

		return DateTimeImmutable::createFromFormat(
			'!Y-m-d',
			date('Y-m-d', $timestamp),
			new DateTimeZone('UTC'),
		) ?: null;
	}
}
