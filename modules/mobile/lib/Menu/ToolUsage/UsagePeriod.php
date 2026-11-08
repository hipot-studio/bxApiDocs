<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\ToolUsage;

/**
 * Observation periods of the preset automation, resolved from tourist events without touching storage
 * or the clock: the caller passes both. A period is an array {days, savedTs, targetTs} - the shape the
 * client writes and the rest of the feature reads.
 */
final class UsagePeriod
{
	public const EVENT_PREFIX = 'tool_usage:period_';
	public const MAX_DAYS = 120;

	/**
	 * @param array<string, array> $events
	 * @return array{days: int, savedTs: int, targetTs: int}|null
	 */
	public static function resolveLatest(array $events): ?array
	{
		$candidates = [];

		foreach (self::filterPeriodEvents($events) as $eventId => $eventData)
		{
			$candidate = self::buildCandidate($eventId, $eventData);
			if ($candidate !== null)
			{
				$candidates[$candidate['days']] = $candidate;
			}
		}

		if (empty($candidates))
		{
			return null;
		}

		return $candidates[max(array_keys($candidates))] ?? null;
	}

	/**
	 * @param array{days: int, savedTs: int, targetTs: int}|null $period
	 */
	public static function isReady(?array $period, int $nowTs): bool
	{
		if ($period === null)
		{
			return false;
		}

		return $nowTs >= (int)($period['targetTs'] ?? 0);
	}

	/**
	 * The last period is not analysed: reaching its target closes the whole observation.
	 *
	 * @param array{days: int, savedTs: int, targetTs: int}|null $period
	 */
	public static function isFinished(?array $period, int $nowTs): bool
	{
		if ($period === null)
		{
			return false;
		}

		return (int)($period['days'] ?? 0) >= self::MAX_DAYS && $nowTs >= (int)($period['targetTs'] ?? 0);
	}

	/**
	 * @param array<string, array> $events
	 * @return array<string, array>
	 */
	private static function filterPeriodEvents(array $events): array
	{
		$result = [];

		foreach ($events as $eventId => $eventData)
		{
			if (str_starts_with($eventId, self::EVENT_PREFIX))
			{
				$result[$eventId] = $eventData;
			}
		}

		return $result;
	}

	/**
	 * @return array{days: int, savedTs: int, targetTs: int}|null
	 */
	private static function buildCandidate(string $eventId, array $eventData): ?array
	{
		$days = (int)substr($eventId, strlen(self::EVENT_PREFIX));
		if ($days <= 0)
		{
			return null;
		}

		$context = EventContext::decode($eventData);
		if ($context === null || !isset($context['savedTs'], $context['targetTs']))
		{
			return null;
		}

		return [
			'days' => $days,
			'savedTs' => (int)$context['savedTs'],
			'targetTs' => (int)$context['targetTs'],
		];
	}
}
