<?php

declare(strict_types=1);

namespace Bitrix\Sale\Public\Dto;

use Bitrix\Main\Entity\EntityCollection;

/**
 * @method BuyerStatistic|null find(callable $callback)
 */
final class BuyerStatisticCollection extends EntityCollection
{
	/**
	 * @return int[]
	 */
	public function getIds(): array
	{
		return $this->map(static fn(BuyerStatistic $statistic): int => $statistic->id);
	}

	/**
	 * Buyers of the rows, each of them once.
	 *
	 * @return int[]
	 */
	public function getUserIds(): array
	{
		return array_values(array_unique($this->map(static fn(BuyerStatistic $statistic): int => $statistic->userId)));
	}

	protected static function getEntityClass(): string
	{
		return BuyerStatistic::class;
	}
}
