<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\ToolUsage;

/**
 * Turns per-tool visit events into two counter maps - one for the bottom navigation, one for the More
 * menu. The allowed tool ids are passed in: what is trackable is decided by the tab inventory, not here.
 */
final class UsageCounters
{
	public const VISIT_EVENT_PREFIX = 'tool_usage:tool_';

	/**
	 * Counters of a foreign period are skipped: the client resets a tool context lazily, on its first
	 * visit within the new period, so events of the previous one stay in storage until then.
	 *
	 * @param array<string, array> $events
	 * @param string[] $allowedToolIds
	 * @return array{bottom: array<string, int>, menu: array<string, int>}
	 */
	public static function fromEvents(array $events, array $allowedToolIds, int $currentPeriodSavedTs): array
	{
		$allowed = array_flip(array_map('strval', $allowedToolIds));
		$counters = [
			ToolUsage::STATS_KEY_BOTTOM => [],
			ToolUsage::STATS_KEY_MENU => [],
		];

		foreach ($events as $eventId => $eventData)
		{
			if (!str_starts_with($eventId, self::VISIT_EVENT_PREFIX))
			{
				continue;
			}

			$toolId = substr($eventId, strlen(self::VISIT_EVENT_PREFIX));
			if (!self::isAllowedToolId($toolId, $allowed))
			{
				continue;
			}

			$context = EventContext::decode($eventData);
			if ($context === null)
			{
				continue;
			}

			$periodSavedTs = isset($context['periodSavedTs']) ? (int)$context['periodSavedTs'] : 0;
			if ($currentPeriodSavedTs > 0 && $periodSavedTs !== $currentPeriodSavedTs)
			{
				continue;
			}

			$sources = $context['sources'] ?? [];
			if (!is_array($sources))
			{
				continue;
			}

			$bottom = $sources[ToolUsage::SOURCE_BOTTOM] ?? null;
			$menu = $sources[ToolUsage::SOURCE_MENU] ?? null;

			if (is_array($bottom) && isset($bottom['count']))
			{
				$counters[ToolUsage::STATS_KEY_BOTTOM][$toolId] = (int)$bottom['count'];
			}
			if (is_array($menu) && isset($menu['count']))
			{
				$counters[ToolUsage::STATS_KEY_MENU][$toolId] = (int)$menu['count'];
			}
		}

		return $counters;
	}

	/**
	 * @param array<string, int> $allowedFlip Allowed tool ids as keys.
	 */
	public static function isAllowedToolId(string $toolId, array $allowedFlip): bool
	{
		if ($toolId === '')
		{
			return false;
		}

		if (isset($allowedFlip[$toolId]))
		{
			return true;
		}

		return str_starts_with($toolId, ToolUsage::CRM_CUSTOM_SECTION_PREFIX);
	}
}
