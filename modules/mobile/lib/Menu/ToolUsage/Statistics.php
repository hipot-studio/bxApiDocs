<?php

namespace Bitrix\Mobile\Menu\ToolUsage;

use Bitrix\Mobile\Tab\Manager;
use Bitrix\Mobile\Tourist;

class Statistics
{
	private const TOOL_EVENT_PREFIX = 'tool_usage:';

	public function getStats(?array $period, ?array $preloadedEvents = null): array
	{
		$toolEvents = $this->filterToolUsageEvents($preloadedEvents ?? $this->getTouristEvents());
		$currentPeriodSavedTs = isset($period['savedTs']) ? (int)$period['savedTs'] : 0;

		$stats = UsageCounters::fromEvents($toolEvents, ToolUsage::getAllowedTools(), $currentPeriodSavedTs);
		$this->ensureActiveBottomTabsPresent($stats);

		return $stats;
	}

	public function getLatestPeriod(?array $preloadedEvents = null): ?array
	{
		$toolEvents = $this->filterToolUsageEvents($preloadedEvents ?? $this->getTouristEvents());

		return UsagePeriod::resolveLatest($toolEvents);
	}

	public function getTouristEvents(): array
	{
		return Tourist::getEvents();
	}

	private function filterToolUsageEvents(array $events): array
	{
		$result = [];
		foreach ($events as $eventId => $data)
		{
			if (str_starts_with($eventId, self::TOOL_EVENT_PREFIX))
			{
				$result[$eventId] = $data;
			}
		}

		return $result;
	}

	/**
	 * An active tab the user never opened has no visit event at all, but it is exactly the candidate for
	 * replacement, so it enters the counters with a zero.
	 */
	private function ensureActiveBottomTabsPresent(array &$stats): void
	{
		$allowed = array_flip(array_map('strval', ToolUsage::getAllowedTools()));
		$manager = new Manager();

		foreach (array_keys($manager->getActiveTabs()) as $tabId)
		{
			$tabInstance = $manager->getTabInstance($tabId);
			$toolId = $tabInstance !== null ? $tabInstance->getId() : $tabId;

			if (!UsageCounters::isAllowedToolId((string)$toolId, $allowed))
			{
				continue;
			}

			if (!isset($stats[ToolUsage::STATS_KEY_BOTTOM][$toolId]))
			{
				$stats[ToolUsage::STATS_KEY_BOTTOM][$toolId] = 0;
			}
		}
	}
}
