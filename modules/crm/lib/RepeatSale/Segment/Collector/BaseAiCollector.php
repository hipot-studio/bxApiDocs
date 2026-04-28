<?php

namespace Bitrix\Crm\RepeatSale\Segment\Collector;

use Bitrix\Crm\RepeatSale\Segment\Data\LastSegmentData;
use Bitrix\Crm\RepeatSale\Segment\Data\SegmentData;
use Bitrix\Crm\RepeatSale\Segment\Data\SegmentDataInterface;
use Bitrix\Crm\RepeatSale\Segment\Data\WrongSegmentData;
use CCrmOwnerType;

abstract class BaseAiCollector extends BaseCollector
{
	protected function createSegmentData(int $entityTypeId, array $filter, int $minimumDaysAfterLastClosedEntity): SegmentDataInterface
	{
		if ($entityTypeId !== CCrmOwnerType::Deal)
		{
			return new WrongSegmentData();
		}

		$items = $this->getItems($entityTypeId, $filter);
		if (empty($items))
		{
			$lastItemId = $filter['>ID'] ?? 0;

			return new LastSegmentData($entityTypeId, $lastItemId);
		}

		$filteredItemIds = $this->getFilteredItemIds($items, $filter);
		$filteredItems = $this->getItemsByIds($filteredItemIds, $entityTypeId);

		return new SegmentData(
			$filteredItems,
			$entityTypeId,
			array_pop($items)['ID'],
		);
	}

	abstract protected function getItems(int $entityTypeId, array $filter): array;
	abstract protected function getFilteredItemIds(array $items, array $filter): array;
}
