<?php

namespace Bitrix\Crm\RepeatSale\Segment\Collector;

use Bitrix\Crm\RepeatSale\Segment\Data\SegmentDataInterface;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Traits\Singleton;

abstract class BaseCollector
{
	use Singleton;

	protected int $limit = 50;
	protected bool $isOnlyCalc = false;

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

	public function getSegmentData(int $entityTypeId, ?int $lastItemId = null): SegmentDataInterface
	{
		$filter = [];
		if ($lastItemId > 0)
		{
			$filter['>ID'] = $lastItemId;
		}

		return $this->createSegmentData($entityTypeId, $filter);
	}

	abstract protected function createSegmentData(int $entityTypeId, array $filter): SegmentDataInterface;

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
}
