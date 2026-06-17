<?php

namespace Bitrix\Crm\RepeatSale\Segment\Collector\Ai;

final class AiScreeningCollector extends BaseAiCollector
{
	private ?DealsProvider $dealsProvider = null;

	protected function getItems(int $entityTypeId, array $filter): array
	{
		return $this->getDealsProvider()->getItems($filter);
	}

	protected function getFilteredItemIds(array $items, array $filter): array
	{
		return $this->getDealsProvider()->getFilteredItemIds($items);
	}

	private function getDealsProvider(): DealsProvider
	{
		if ($this->dealsProvider === null)
		{
			$this->dealsProvider = new DealsProvider($this->limit);
		}

		return $this->dealsProvider;
	}
}
