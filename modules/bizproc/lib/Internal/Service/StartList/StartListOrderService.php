<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\StartList;

use Bitrix\Bizproc\Internal\Config\StartListOrder;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

/**
 * The order of the start list: what an employee pinned, then what the portal published for the first
 * time within the novelty window, then everything else by the number of manual starts that finished
 * successfully over the popularity window.
 *
 * Names are never compared here. Rows come in the order the database produced them - NAME ASC, ID DESC
 * under the collation of the portal - and templates equal by the measure of their group keep it, so the
 * comparison of names stays where it belongs and the sort field of the row plays no part at all.
 *
 * The bounds of the novelty window are measured from the moment the caller passes and never from the
 * clock inside a comparison: a window of exactly 168 hours is nothing a test could pin down otherwise.
 * Both bounds are timestamps, so a daylight saving transition leaves the window as wide as it was
 * configured instead of adding or dropping an hour of it.
 */
final class StartListOrderService
{
	private const GROUP_PINNED = 0;
	private const GROUP_NEW = 1;
	private const GROUP_POPULAR = 2;

	private const SECONDS_IN_HOUR = 3600;

	private readonly int $noveltyHours;

	public function __construct(?int $noveltyHours = null)
	{
		$this->noveltyHours = $noveltyHours ?? StartListOrder::getNoveltyHours();
	}

	/**
	 * @param array<int, array{id: int, pinId: int|null, firstPublishedAt: Date|null}> $items Rows in the
	 * order of the selection; their positions are the tie-breaker of the last resort, so that the result
	 * does not depend on how stable the sort of a version of PHP happens to be.
	 * @param array<int, int> $popularity Successful manual starts per template; a template missing from
	 * the map has none.
	 *
	 * @return int[] Identifiers of the templates in the order of the list.
	 */
	public function getOrderedIds(array $items, array $popularity, DateTime $now): array
	{
		$nowTimestamp = $now->getTimestamp();
		$noveltyFrom = $nowTimestamp - ($this->noveltyHours * self::SECONDS_IN_HOUR);

		$rows = [];
		foreach (array_values($items) as $originalIndex => $item)
		{
			$rows[] = self::toRow($item, $popularity, $noveltyFrom, $nowTimestamp, $originalIndex);
		}

		usort($rows, self::compare(...));

		return array_column($rows, 'id');
	}

	/**
	 * @param array{id: int, pinId: int|null, firstPublishedAt: Date|null} $item
	 * @param array<int, int> $popularity
	 *
	 * @return array{id: int, group: int, pinId: int, publishedAt: int, popularity: int, originalIndex: int}
	 */
	private static function toRow(
		array $item,
		array $popularity,
		int $noveltyFrom,
		int $nowTimestamp,
		int $originalIndex,
	): array
	{
		$id = (int)($item['id'] ?? 0);

		$pinId = $item['pinId'] ?? null;
		$pinId = $pinId === null ? null : (int)$pinId;

		$firstPublishedAt = $item['firstPublishedAt'] ?? null;
		$publishedAt = $firstPublishedAt instanceof Date ? $firstPublishedAt->getTimestamp() : null;

		return [
			'id' => $id,
			'group' => self::groupOf($pinId, $publishedAt, $noveltyFrom, $nowTimestamp),
			'pinId' => $pinId ?? 0,
			'publishedAt' => $publishedAt ?? 0,
			'popularity' => (int)($popularity[$id] ?? 0),
			'originalIndex' => $originalIndex,
		];
	}

	/**
	 * A template published ahead of the control moment is not new yet: a date of the future belongs to a
	 * mistake of the data and must not hold the top of the list until it passes.
	 */
	private static function groupOf(?int $pinId, ?int $publishedAt, int $noveltyFrom, int $nowTimestamp): int
	{
		if ($pinId !== null)
		{
			return self::GROUP_PINNED;
		}

		if ($publishedAt !== null && $publishedAt >= $noveltyFrom && $publishedAt <= $nowTimestamp)
		{
			return self::GROUP_NEW;
		}

		return self::GROUP_POPULAR;
	}

	private static function compare(array $a, array $b): int
	{
		if ($a['group'] !== $b['group'])
		{
			return $a['group'] <=> $b['group'];
		}

		$withinGroup = match ($a['group'])
		{
			self::GROUP_PINNED => $b['pinId'] <=> $a['pinId'],
			self::GROUP_NEW => $b['publishedAt'] <=> $a['publishedAt'],
			default => $b['popularity'] <=> $a['popularity'],
		};

		return $withinGroup === 0 ? $a['originalIndex'] <=> $b['originalIndex'] : $withinGroup;
	}
}
