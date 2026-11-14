<?php

declare(strict_types=1);

namespace Bitrix\Call\Reaction;

/**
 * The order of the quick row, decided here and nowhere else.
 *
 * Web and mobile show the same six pictures in the same places because both ask the server for them
 * instead of ranking their own copy of the statistics: the history lives per user, not per device.
 *
 * The reactions sent last lead the row, the newest first, and the most used ones follow. A client lifts
 * a reaction it has just sent the same way, so the row after a page reload matches the one before it.
 */
final class QuickListProvider
{
	private const QUICK_LIST_SIZE = 6;

	public function __construct(private readonly ReactionUsageStore $usageStore = new ReactionUsageStore())
	{
	}

	/**
	 * @return string[] exactly {@see self::QUICK_LIST_SIZE} visible reaction ids
	 */
	public function getQuickList(int $userId): array
	{
		if ($userId < 1)
		{
			return ReactionCatalog::SEED;
		}

		$visible = ReactionCatalog::getVisible();
		$usage = $this->usageStore->read($userId);

		$recent = array_reverse(array_values(array_intersect($usage['recent'], $visible)));
		$counters = array_diff_key(
			array_intersect_key($usage['counters'], array_flip($visible)),
			array_flip($recent),
		);

		$quickList = [...$recent, ...$this->orderByUse($counters, $visible)];
		foreach (ReactionCatalog::SEED as $seedId)
		{
			if (count($quickList) >= self::QUICK_LIST_SIZE)
			{
				break;
			}

			if (!in_array($seedId, $quickList, true))
			{
				$quickList[] = $seedId;
			}
		}

		return array_slice($quickList, 0, self::QUICK_LIST_SIZE);
	}

	/**
	 * Most used first. Equal counts keep the seed order and then the catalog order.
	 *
	 * @param array<string, int> $counters
	 * @param string[] $visible
	 * @return string[]
	 */
	private function orderByUse(array $counters, array $visible): array
	{
		$rank = $this->declaredOrder($visible);
		$reactionIds = array_keys($counters);

		usort($reactionIds, static function (string $left, string $right) use ($counters, $rank): int {
			return ($counters[$right] <=> $counters[$left]) ?: ($rank[$left] <=> $rank[$right]);
		});

		return $reactionIds;
	}

	/**
	 * @param string[] $visible
	 * @return array<string, int> reactionId => position, seed ids ahead of the rest of the catalog
	 */
	private function declaredOrder(array $visible): array
	{
		$order = array_flip(ReactionCatalog::SEED);
		$catalogueOffset = count($order);

		foreach ($visible as $position => $reactionId)
		{
			$order[$reactionId] ??= $catalogueOffset + $position;
		}

		return $order;
	}
}
