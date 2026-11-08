<?php

namespace Bitrix\Mobile\Menu\ToolUsage;

use Bitrix\Mobile\Context;
use Bitrix\Mobile\Tab\Manager;
use Bitrix\Mobile\Tourist;

class ToolUsage
{
	public const SOURCE_BOTTOM = 'bottomNavigation';
	public const SOURCE_MENU = 'menu';
	public const STATS_KEY_BOTTOM = 'bottom';
	public const STATS_KEY_MENU = 'menu';

	private const EXCLUDED_TABS = ['chat', 'menu', 'stream'];
	public const CRM_CUSTOM_SECTION_PREFIX = 'crm_custom_section-';
	private const MENU_SET_EVENT = 'tool_usage:menu_set';
	public const MENU_BANNER_SHOWN_EVENT = 'tool_usage:menu_banner_shown';
	public const MENU_BANNER_EVENT = 'tool_usage:menu_banner';
	private const PROCESS_EVENT = 'tool_usage:processed';
	public const REPLACEMENT_EVENT = 'tool_usage:menu_replaced';
	public const REPLACEMENT_SHOWN_EVENT = 'tool_usage:replacement_shown';

	private Statistics $statistics;

	private static ?array $allowedToolsCache = null;

	private ?array $events = null;
	private ?array $period = null;

	public function __construct()
	{
		$this->statistics = new Statistics();
	}

	public static function isTrackingAvailable(): bool
	{
		$context = new Context();

		return !$context->isGuest && !$context->isCollaber;
	}

	public static function getAllowedTools(): array
	{
		if (self::$allowedToolsCache !== null)
		{
			return self::$allowedToolsCache;
		}

		if (!self::isTrackingAvailable())
		{
			self::$allowedToolsCache = [];

			return self::$allowedToolsCache;
		}

		$manager = new Manager();
		$tabIds = $manager->getAllTabIDs(true);
		$toolIds = [];

		foreach ($tabIds as $tabId)
		{
			if (in_array($tabId, self::EXCLUDED_TABS, true))
			{
				continue;
			}

			$tabInstance = $manager->getTabInstance($tabId);
			if ($tabInstance === null)
			{
				continue;
			}

			$toolIds[] = $tabInstance->getId();
		}

		self::$allowedToolsCache = $toolIds;

		return $toolIds;
	}

	public function processOnceForPeriod(): array
	{
		if (!self::isTrackingAvailable())
		{
			return ['canStartNextPeriod' => false];
		}

		$this->loadBaseData();

		if ($this->isProcessingCompleted())
		{
			$shouldShowReplacementBanner = $this->shouldShowReplacementBanner();

			return [
				'canStartNextPeriod' => false,
				'showReplacementBanner' => $shouldShowReplacementBanner,
			];
		}

		if (!UsagePeriod::isReady($this->period, time()))
		{
			return ['canStartNextPeriod' => false];
		}

		$periodSavedTs = (int)($this->period['savedTs'] ?? 0);
		if ($periodSavedTs <= 0)
		{
			return ['canStartNextPeriod' => false];
		}

		if ($this->wasProcessedForPeriod($periodSavedTs))
		{
			return ['canStartNextPeriod' => false];
		}

		$stats = $this->statistics->getStats($this->period, $this->events);
		$bottomTools = $stats[self::STATS_KEY_BOTTOM] ?? [];
		$menuTools = $stats[self::STATS_KEY_MENU] ?? [];

		$replaced = false;
		$menuBanner = false;

		if (ReplacementPolicy::hasUnderusedBottomTool($bottomTools))
		{
			$decision = ReplacementPolicy::findReplacement($bottomTools, $menuTools);
			if ($decision !== null)
			{
				$oldToolId = $decision['oldToolId'];
				$newToolId = $decision['newToolId'];
				$replaced = (new Manager())->replaceActiveTab($oldToolId, $newToolId);
				if ($replaced)
				{
					$this->rememberReplacementEvent($oldToolId, $newToolId);
				}
			}
		}
		else
		{
			$menuBanner = ReplacementPolicy::shouldShowMenuBanner($bottomTools, $menuTools);
			if ($menuBanner)
			{
				$this->rememberMenuBanner($periodSavedTs);
			}
		}

		if ($replaced || $menuBanner)
		{
			$this->rememberProcessed($periodSavedTs, $replaced, $menuBanner);

			return ['canStartNextPeriod' => false];
		}

		return ['canStartNextPeriod' => true];
	}

	public function shouldShowMenuBanner(): bool
	{
		if (!self::isTrackingAvailable())
		{
			return false;
		}

		$this->loadBaseData();

		if (UsagePeriod::isFinished($this->period, time()))
		{
			return false;
		}

		if (self::isMenuCustomized($this->events) || self::isMenuBannerShown($this->events))
		{
			return false;
		}

		if (!UsagePeriod::isReady($this->period, time()))
		{
			return false;
		}

		return $this->isMenuBannerEnabledForCurrentPeriod();
	}

	private function shouldShowReplacementBanner(): bool
	{
		return !empty($this->events[self::REPLACEMENT_EVENT])
			&& empty($this->events[self::REPLACEMENT_SHOWN_EVENT])
			&& !self::isMenuCustomized($this->events);
	}

	private function loadBaseData(): void
	{
		if ($this->events !== null)
		{
			return;
		}

		$this->events = $this->statistics->getTouristEvents();
		$this->period = $this->statistics->getLatestPeriod($this->events);
	}

	private function wasProcessedForPeriod(int $periodSavedTs): bool
	{
		$context = $this->getEventContextArray(self::PROCESS_EVENT);
		$savedTs = isset($context['periodSavedTs']) ? (int)$context['periodSavedTs'] : 0;

		return $savedTs === $periodSavedTs;
	}

	private function rememberProcessed(int $periodSavedTs, bool $replaced, bool $menuBanner): void
	{
		Tourist::remember(self::PROCESS_EVENT, json_encode([
			'periodSavedTs' => $periodSavedTs,
			'replaced' => $replaced ? 1 : 0,
			'menuBanner' => $menuBanner ? 1 : 0,
		], JSON_THROW_ON_ERROR));
	}

	private function rememberMenuBanner(int $periodSavedTs): void
	{
		Tourist::remember(self::MENU_BANNER_EVENT, json_encode([
			'periodSavedTs' => $periodSavedTs,
		], JSON_THROW_ON_ERROR));
	}

	private function isMenuBannerEnabledForCurrentPeriod(): bool
	{
		if (!is_array($this->events) || empty($this->events[self::MENU_BANNER_EVENT]))
		{
			return false;
		}

		$periodSavedTs = (int)($this->period['savedTs'] ?? 0);
		if ($periodSavedTs <= 0)
		{
			return false;
		}

		$context = $this->getEventContextArray(self::MENU_BANNER_EVENT);
		$eventPeriodSavedTs = isset($context['periodSavedTs']) ? (int)$context['periodSavedTs'] : 0;
		if ($eventPeriodSavedTs > 0 && $eventPeriodSavedTs !== $periodSavedTs)
		{
			return false;
		}

		return true;
	}

	private function getEventContextArray(string $eventId): ?array
	{
		if (!is_array($this->events) || empty($this->events[$eventId]))
		{
			return null;
		}

		return EventContext::decode($this->events[$eventId]);
	}

	private static function isMenuCustomized(array $events): bool
	{
		return !empty($events[self::MENU_SET_EVENT]);
	}

	private function isProcessingCompleted(): bool
	{
		return UsagePeriod::isFinished($this->period, time())
			|| self::isMenuCustomized($this->events)
			|| self::isMenuBannerShown($this->events)
			|| !empty($this->events[self::REPLACEMENT_EVENT]);
	}

	private static function isMenuBannerShown(array $events): bool
	{
		return !empty($events[self::MENU_BANNER_SHOWN_EVENT]);
	}

	private function rememberReplacementEvent(string $oldToolId, string $newToolId): void
	{
		Tourist::remember(self::REPLACEMENT_EVENT, json_encode([
			'oldToolId' => $oldToolId,
			'newToolId' => $newToolId,
		], JSON_THROW_ON_ERROR));
	}
}
