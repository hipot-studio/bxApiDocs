<?php

namespace Bitrix\Crm\RepeatSale\Segment\Collector;

use Bitrix\Crm\RepeatSale\Service\Entity\RepeatSaleAiScreeningTable;
use Bitrix\Crm\RepeatSale\Service\Handler\AiScreeningOpinion;
use Bitrix\Main\Type\Date;

final class AiApproveCollector extends BaseAiCollector
{
	protected function getItems(int $entityTypeId, array $filter): array
	{
		return RepeatSaleAiScreeningTable::query()
			->setSelect(['ID', 'OWNER_ID'])
			->setFilter([
				'=OWNER_TYPE_ID' => $entityTypeId,
				'=AI_OPINION' => AiScreeningOpinion::isRepeatSalePossible->value,
				'=RESULT_ENTITY_TYPE_ID' => null,
				'=RESULT_ENTITY_ID' => null,
				'<=DESIRED_CREATION_DATE' => (new Date())->add('1 day'),
				'>ID' => $filter['>ID'] ?? 0,
			])
			->setOrder(['ID' => 'ASC'])
			->setLimit($this->limit)
			->fetchAll()
		;
	}

	protected function getFilteredItemIds(array $items, array $filter): array
	{
		return array_column($items, 'OWNER_ID');
	}
}
