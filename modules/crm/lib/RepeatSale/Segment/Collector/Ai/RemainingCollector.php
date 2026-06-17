<?php

namespace Bitrix\Crm\RepeatSale\Segment\Collector\Ai;

use Bitrix\Crm\RepeatSale\Service\Entity\RepeatSaleAiScreeningTable;
use Bitrix\Crm\RepeatSale\Service\Handler\AiScreeningOpinion;

final class RemainingCollector extends BaseAiCollector
{
	private ?DealsProvider $dealsProvider = null;
	private bool $isHolidaySegmentEnabled;

	protected function getItems(int $entityTypeId, array $filter): array
	{
		if ($this->isHolidaySegmentEnabled)
		{
			return RepeatSaleAiScreeningTable::query()
				->setSelect(['ID', 'OWNER_ID'])
				->setFilter([
					'=OWNER_TYPE_ID' => $entityTypeId,
					'=AI_OPINION' => AiScreeningOpinion::isRepeatSaleNotPossible->value,
					'=RESULT_ENTITY_TYPE_ID' => null,
					'=RESULT_ENTITY_ID' => null,
					'>ID' => $filter['>ID'] ?? 0,
				])
				->setOrder(['ID' => 'ASC'])
				->setLimit($this->limit)
				->fetchAll()
			;
		}

		return $this->getDealsProvider()->getItems($filter);
	}

	protected function getFilteredItemIds(array $items, array $filter): array
	{
		if ($this->isHolidaySegmentEnabled)
		{
			return array_column($items, 'OWNER_ID');
		}

		return $this->getDealsProvider()->getFilteredItemIds($items);
	}

	public function setIsHolidaySegmentEnabled(bool $isHolidaySegmentEnabled): self
	{
		$this->isHolidaySegmentEnabled = $isHolidaySegmentEnabled;

		return $this;
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