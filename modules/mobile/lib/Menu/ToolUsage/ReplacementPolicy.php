<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\ToolUsage;

/**
 * Decides what the collected counters mean: whether the bottom navigation holds a tool the user barely
 * opens, which pair is worth swapping and whether the More menu has anything to advertise. Counters in,
 * decision out - no storage, no tabs, no clock.
 */
final class ReplacementPolicy
{
	public const MIN_USAGE_THRESHOLD = 5;
	public const REPLACEMENT_RATIO = 1.3;

	/**
	 * @param array<string, int> $bottomCounts
	 */
	public static function hasUnderusedBottomTool(array $bottomCounts): bool
	{
		return !empty($bottomCounts) && min($bottomCounts) < self::MIN_USAGE_THRESHOLD;
	}

	/**
	 * The least used tool of the bottom navigation gives up its slot to the most used tool of the More
	 * menu, but only if the latter is used at least REPLACEMENT_RATIO times as often.
	 *
	 * @param array<string, int> $bottomCounts
	 * @param array<string, int> $menuCounts
	 * @return array{oldToolId: string, newToolId: string}|null
	 */
	public static function findReplacement(array $bottomCounts, array $menuCounts): ?array
	{
		if (empty($bottomCounts) || empty($menuCounts))
		{
			return null;
		}

		asort($bottomCounts);
		$oldToolId = array_key_first($bottomCounts);

		arsort($menuCounts);
		$newToolId = array_key_first($menuCounts);

		if ($oldToolId === null || $newToolId === null)
		{
			return null;
		}

		$oldCount = (int)$bottomCounts[$oldToolId];
		$newCount = (int)$menuCounts[$newToolId];

		if ($newCount <= ($oldCount * self::REPLACEMENT_RATIO))
		{
			return null;
		}

		return [
			'oldToolId' => (string)$oldToolId,
			'newToolId' => (string)$newToolId,
		];
	}

	/**
	 * @param array<string, int> $bottomCounts
	 * @param array<string, int> $menuCounts
	 */
	public static function shouldShowMenuBanner(array $bottomCounts, array $menuCounts): bool
	{
		if (empty($bottomCounts) || empty($menuCounts))
		{
			return false;
		}

		return max(array_map('intval', $menuCounts)) > min(array_map('intval', $bottomCounts));
	}
}
