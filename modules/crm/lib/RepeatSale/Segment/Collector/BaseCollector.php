<?php

namespace Bitrix\Crm\RepeatSale\Segment\Collector;

use Bitrix\Crm\RepeatSale\Segment\Collector\Filter\RecentlyClosedEntityFilter;
use Bitrix\Crm\RepeatSale\Segment\Data\SegmentDataInterface;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\Type\Date;

abstract class BaseCollector
{
	use Singleton;

	protected int $limit = 50;
	protected bool $isOnlyCalc = false;
	protected ?Date $date = null;

	public function setLimit(int $limit): self
	{
		$this->limit = $limit;

		return $this;
	}

	public function setIsOnlyCalc(bool $isOnlyCalc): self
	{
		$this->isOnlyCalc = $isOnlyCalc;

		return $this;
	}

	public function setDate(?Date $date): self
	{
		$this->date = $date;

		return $this;
	}

	public function getSegmentData(
		int $entityTypeId,
		?int $lastItemId = null,
		int $minimumDaysAfterLastClosedEntity = 0,
	): SegmentDataInterface
	{
		$filter = [];
		if ($lastItemId > 0)
		{
			$filter['>ID'] = $lastItemId;
		}

		return $this->createSegmentData($entityTypeId, $filter, $minimumDaysAfterLastClosedEntity);
	}

	abstract protected function createSegmentData(
		int $entityTypeId,
		array $filter,
		int $minimumDaysAfterLastClosedEntity,
	): SegmentDataInterface;

	final protected function getItemsByIds(array $ids, int $entityTypeId): array
	{
		if (empty($ids))
		{
			return [];
		}

		$factory = Container::getInstance()->getFactory($entityTypeId);

		return $factory?->getItems([
			'select' => $this->getItemFields(),
			'filter' => [
				'@ID' => $ids,
			],
		]) ?? [];
	}

	protected function getItemFields(): array
	{
		return ['ID'];
	}

	protected function filterItemsWithRecentlyClosedEntities(
		array $ids,
		int $entityTypeId,
		int $minimumDaysAfterLastClosedEntity,
	): array
	{
		return (new RecentlyClosedEntityFilter())->filter($ids, $entityTypeId, $minimumDaysAfterLastClosedEntity);
	}
}
