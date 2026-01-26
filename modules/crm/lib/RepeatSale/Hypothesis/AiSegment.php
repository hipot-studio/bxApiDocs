<?php

namespace Bitrix\Crm\RepeatSale\Hypothesis;

use Bitrix\Crm\DealTable;
use Bitrix\Crm\Format\TextHelper;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\ActivityDataCollector;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\StrategyFactory;
use Bitrix\Crm\RepeatSale\DataCollector\CopilotMarkerLimitManager;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\Date;
use CCrmOwnerType;

final class AiSegment
{
	use Singleton;

	private int $limit = 50;
	private ?Date $date = null;

	public function setDate(?Date $date): self
	{
		$this->date = $date;

		return $this;
	}

	public function execute(int $entityTypeId, ?int $lastItemId = null): array
	{
		if ($this->date === null)
		{
			$this->date = (new Date())->add('-1 year');
		}

		$filter = [];
		if ($lastItemId > 0)
		{
			$filter['>ID'] = $lastItemId;
		}

		$items = $this->getItems($entityTypeId, $filter);
		if (empty($items))
		{
			return [
				'count' => 0,
				'nextItemId' => null,
			];
		}

		$itemIds = $this->getFilteredIds($items);
		if (empty($itemIds))
		{
			return [
				'count' => 0,
				'nextItemId' => array_pop($items)['ID'],
			];
		}

		return [
			'count' => count($itemIds),
			'nextItemId' => array_pop($itemIds),
		];
	}

	protected function getItems(int $entityTypeId, array $filter): array
	{
		$dealsQuery = $this->getItemsQuery($filter);

		return $dealsQuery->exec()->fetchAll();
	}

	protected function getFilteredIds(array $items): array
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
		$start = $this->date;
		$finish = (clone $this->date)->add('1 day');

		$ct = (new ConditionTree())
			->logic(ConditionTree::LOGIC_OR)
			->where('COMPANY_ID', '>', 0)
			->where('CONTACT_ID', '>', 0)
		;

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

	private function getContactsWithActiveDealsQuery(array $contactIds): Query
	{
		return DealTable::query()
			->addSelect('CONTACT_ID')
			->whereIn('CONTACT_ID', $contactIds)
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
		if (empty($data))
		{
			return false;
		}

		$limit = CopilotMarkerLimitManager::getInstance()->getCommunicationFieldsLimit();

		return TextHelper::countCharactersInArrayFlexible($data) > $limit;
	}
}
