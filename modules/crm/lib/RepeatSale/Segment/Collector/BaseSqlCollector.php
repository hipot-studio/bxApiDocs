<?php

namespace Bitrix\Crm\RepeatSale\Segment\Collector;

use Bitrix\Crm\RepeatSale\Segment\Data\LastSegmentData;
use Bitrix\Crm\RepeatSale\Segment\Data\SegmentData;
use Bitrix\Crm\RepeatSale\Segment\Data\SegmentDataInterface;
use Bitrix\Crm\RepeatSale\Segment\Data\WrongSegmentData;
use Bitrix\Crm\Service\Communication\Utils\Common;

abstract class BaseSqlCollector extends BaseCollector
{
	protected function createSegmentData(int $entityTypeId, array $filter, int $minimumDaysAfterLastClosedEntity): SegmentDataInterface
	{
		if (!Common::isClientEntityTypeId($entityTypeId))
		{
			return new WrongSegmentData();
		}

		if ($entityTypeId === \CCrmOwnerType::Contact)
		{
			$ids = $this->getContactIds($filter);
		}
		else
		{
			$ids = $this->getCompanyIds($filter);
		}

		$items = $this->getItemsByIds($ids, $entityTypeId);
		if (empty($items))
		{
			$nextItemId = $this->getNextItemsMinId($entityTypeId, $filter);

			if ($nextItemId)
			{
				return new SegmentData(
					[],
					$entityTypeId,
					$nextItemId,
				);
			}

			$lastItemId = $filter['>ID'] ?? 0;

			return new LastSegmentData($entityTypeId, $lastItemId);
		}

		return new SegmentData(
			$items,
			$entityTypeId,
			array_pop($ids),
		);
	}

	abstract protected function getContactIds(array $filter): array;

	abstract protected function getCompanyIds(array $filter): array;

	abstract protected function getNextItemsMinId(int $entityTypeId, array $filter): ?int;
}
