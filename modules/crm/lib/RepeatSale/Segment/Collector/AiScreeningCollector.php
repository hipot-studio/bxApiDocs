<?php

namespace Bitrix\Crm\RepeatSale\Segment\Collector;

use Bitrix\Crm\DealTable;
use Bitrix\Crm\Format\TextHelper;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\ActivityDataCollector;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\StrategyFactory;
use Bitrix\Crm\RepeatSale\DataCollector\CopilotMarkerLimitManager;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\Date;
use CCrmOwnerType;

final class AiScreeningCollector extends BaseAiCollector
{
	protected function getItems(int $entityTypeId, array $filter): array
	{
		$dealsQuery = $this->getItemsQuery($filter);

		return $dealsQuery->exec()->fetchAll();
	}

	protected function getFilteredItemIds(array $items, array $filter): array
	{
		if (empty($items))
		{
			return [];
		}

		$companyIds = $this->getColumnFromItems($items, 'COMPANY_ID');
		if (empty($companyIds))
		{
			$itemsWithoutActiveDealsByCompany = $items;
		}
		else
		{
			$companiesWithActiveDealsQuery = $this->getCompaniesWithActiveDealsQuery($companyIds);
			$companiesWithActiveDealIds = $this->getIdsFromQuery($companiesWithActiveDealsQuery, 'COMPANY_ID');

			if (empty($companiesWithActiveDealIds))
			{
				$itemsWithoutActiveDealsByCompany = $items;
			}
			else
			{
				$itemsWithoutActiveDealsByCompany = [];
				foreach ($items as $item)
				{
					if (!in_array($item['COMPANY_ID'], $companiesWithActiveDealIds, true))
					{
						$itemsWithoutActiveDealsByCompany[] = $item;
					}
				}

				if (empty($itemsWithoutActiveDealsByCompany))
				{
					return [];
				}
			}
		}

		$contactIds = $this->getColumnFromItems($itemsWithoutActiveDealsByCompany, 'CONTACT_ID');
		if (empty($contactIds))
		{
			$itemsWithoutActiveDealsByCompanyAndContact = $itemsWithoutActiveDealsByCompany;
		}
		else
		{
			$contactsWithActiveDealsQuery = $this->getContactsWithActiveDealsQuery($contactIds);
			$contactsWithActiveDealIds = $this->getIdsFromQuery($contactsWithActiveDealsQuery, 'CONTACT_ID');

			if (empty($contactsWithActiveDealIds))
			{
				$itemsWithoutActiveDealsByCompanyAndContact = $itemsWithoutActiveDealsByCompany;
			}
			else
			{
				$itemsWithoutActiveDealsByCompanyAndContact = [];
				foreach ($itemsWithoutActiveDealsByCompany as $item)
				{
					if (!in_array($item['CONTACT_ID'], $contactsWithActiveDealIds, true))
					{
						$itemsWithoutActiveDealsByCompanyAndContact[] = $item;
					}
				}

				if (empty($itemsWithoutActiveDealsByCompanyAndContact))
				{
					return [];
				}
			}
		}

		$resultItemIds = [];
		foreach ($itemsWithoutActiveDealsByCompanyAndContact as $item)
		{
			$activityCollector = new ActivityDataCollector(CCrmOwnerType::Deal, new StrategyFactory());

			$communicationData = $activityCollector->getMarkers(['entityId' => $item['ID']]);

			if ($this->isCrmDataSufficient($communicationData))
			{
				$resultItemIds[] = $item['ID'];
			}
		}

		return $resultItemIds;
	}

	private function getItemsQuery(array $filter): Query
	{
		$offset = '-1 year 7 days';

		$start = (new Date())->add($offset);
		$finish = (new Date())->add($offset)->add('1 day');

		$ct = (new ConditionTree())
			->logic(ConditionTree::LOGIC_OR)
			->where('COMPANY_ID', '>', 0)
			->where('CONTACT_ID', '>', 0)
		;

		// only deals support
		return DealTable::query()
			->setSelect(['ID', 'COMPANY_ID', 'CONTACT_ID'])
			->where('STAGE_SEMANTIC_ID', PhaseSemantics::SUCCESS)
			->where('DATE_CREATE', '>=', $start)
			->where('DATE_CREATE', '<', $finish)
			->where('ID', '>', $filter['>ID'] ?? 0)
			->where($ct)
			->setOrder(['ID' => 'ASC'])
			->setLimit($this->limit)
		;
	}

	private function getColumnFromItems(array $items, string $columnName = 'ID'): array
	{
		$callback = static fn($value): bool => $value > 0;

		return array_filter(array_column($items, $columnName), $callback);
	}

	private function getCompaniesWithActiveDealsQuery(array $companyIds): Query
	{
		return DealTable::query()
			->addSelect('COMPANY_ID')
			->whereIn('COMPANY_ID', $companyIds)
			->where('CLOSED', '=', 'N')
			->setDistinct()
		;
	}

	private function getContactsWithActiveDealsQuery(array $companyIds): Query
	{
		// only first contact_id used
		return DealTable::query()
			->addSelect('CONTACT_ID')
			->whereIn('CONTACT_ID', $companyIds)
			->where('CLOSED', '=', 'N')
			->setDistinct()
		;
	}

	private function getIdsFromQuery(Query $query, string $columnName = 'COMPANY_ID'): array
	{
		return array_column($query->exec()->fetchAll(), $columnName);
	}

	private function isCrmDataSufficient(array $data): bool
	{
		$limit = CopilotMarkerLimitManager::getInstance()->getMinSufficientAiCollectorCommunicationLength();

		if ($limit === 0)
		{
			return true;
		}

		if (empty($data))
		{
			return false;
		}

		return TextHelper::countCharactersInArrayFlexible($data) > $limit;
	}

	protected function getItemFields(): array
	{
		return ['ID', 'COMPANY_ID', 'CONTACT_ID'];
	}
}
