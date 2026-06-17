<?php

namespace Bitrix\Crm\RepeatSale\AssignmentStrategies;

use Bitrix\Crm\Binding\DealContactTable;
use Bitrix\Crm\DealTable;
use Bitrix\Crm\Item;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\Expressions\ColumnExpression;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use CCrmOwnerType;

final class ByClientLastDeal extends Base
{
	private const AVAILABLE_ENTITY_TYPES = [
		CCrmOwnerType::Contact,
		CCrmOwnerType::Company,
		CCrmOwnerType::Deal,
	];

	private ?array $clientAssignmentIds = [];
	private ?array $contactDealPairs = null;
	private ?array $companyDealRows = null;
	private ?array $dealInfoMap = null;

	public function getAssignmentUserId(Item $item, ?int $lastAssignmentUserId): ?int
	{
		$ids = $this->getAssignmentIds();

		return $ids[$item->getId()] ?? $lastAssignmentUserId;
	}

	private function getAssignmentIds(): array
	{
		if (!isset($this->clientAssignmentIds[$this->entityTypeId]))
		{
			$entityIds = array_map(static fn($item) => $item->getId(), $this->items);

			if (!$this->check($entityIds))
			{
				$this->clientAssignmentIds[$this->entityTypeId] = [];

				return [];
			}

			$assignmentIds = $this->entityTypeId === CCrmOwnerType::Deal
				? $this->getAssignmentIdsForDeals($entityIds)
				: $this->getAssignmentIdsForClients($entityIds)
			;

			$this->clientAssignmentIds[$this->entityTypeId] = $assignmentIds;
		}

		return $this->clientAssignmentIds[$this->entityTypeId];
	}

	private function getAssignmentIdsForDeals(array $dealEntityIds): array
	{
		$latestByContact = $this->findLatestDealByContacts($dealEntityIds);
		$latestByCompany = $this->findLatestDealByCompanies($dealEntityIds);

		if (empty($latestByContact) && empty($latestByCompany))
		{
			return [];
		}

		$dealEntityIdsFlipped = array_flip($dealEntityIds);

		$dealContacts = [];
		foreach ($this->getContactDealPairs($dealEntityIds) as $row)
		{
			$dealId = (int)$row['DEAL_ID'];
			if (isset($dealEntityIdsFlipped[$dealId]))
			{
				$dealContacts[$dealId][] = (int)$row[Item::FIELD_NAME_CONTACT_ID];
			}
		}

		$dealCompanies = [];
		foreach ($this->getCompanyDealRows($dealEntityIds) as $row)
		{
			$dealId = (int)$row['ID'];
			if (isset($dealEntityIdsFlipped[$dealId]))
			{
				$dealCompanies[$dealId] = (int)$row[Item::FIELD_NAME_COMPANY_ID];
			}
		}

		$result = [];
		foreach ($dealEntityIds as $dealId)
		{
			$bestDeal = null;

			foreach ($dealContacts[$dealId] ?? [] as $contactId)
			{
				if (!isset($latestByContact[$contactId]))
				{
					continue;
				}

				$candidate = $latestByContact[$contactId];
				if ($bestDeal === null || $this->isNewerDeal($candidate, $bestDeal))
				{
					$bestDeal = $candidate;
				}
			}

			$companyId = $dealCompanies[$dealId] ?? null;
			if ($companyId !== null && isset($latestByCompany[$companyId]))
			{
				$candidate = $latestByCompany[$companyId];
				if ($bestDeal === null || $this->isNewerDeal($candidate, $bestDeal))
				{
					$bestDeal = $candidate;
				}
			}

			if ($bestDeal !== null)
			{
				$result[$dealId] = $bestDeal['ASSIGNED_BY_ID'];
			}
		}

		return $result;
	}

	private function findLatestDealByContacts(array $dealEntityIds): array
	{
		$rows = $this->getContactDealPairs($dealEntityIds);

		if (empty($rows))
		{
			return [];
		}

		$contactDealMap = [];
		$allDealIds = [];
		foreach ($rows as $row)
		{
			$contactDealMap[(int)$row[Item::FIELD_NAME_CONTACT_ID]][] = (int)$row['DEAL_ID'];
			$allDealIds[(int)$row['DEAL_ID']] = true;
		}

		$dealInfo = $this->getDealInfo(array_keys($allDealIds));

		$result = [];
		foreach ($contactDealMap as $contactId => $dealIds)
		{
			$best = null;
			foreach ($dealIds as $dealId)
			{
				if (!isset($dealInfo[$dealId]))
				{
					continue;
				}

				if ($best === null || $this->isNewerDeal($dealInfo[$dealId], $best))
				{
					$best = $dealInfo[$dealId];
				}
			}

			if ($best !== null)
			{
				$result[$contactId] = $best;
			}
		}

		return $result;
	}

	private function getContactDealPairs(array $dealEntityIds): array
	{
		if (empty($dealEntityIds))
		{
			return [];
		}

		if ($this->contactDealPairs === null)
		{
			$subQuery = DealContactTable::query()
				->addSelect(Item::FIELD_NAME_CONTACT_ID)
				->whereIn('DEAL_ID', $dealEntityIds)
			;

			$query = DealContactTable::query();
			$query->setSelect([Item::FIELD_NAME_CONTACT_ID, 'DEAL_ID']);
			$query->whereIn(Item::FIELD_NAME_CONTACT_ID, new SqlExpression($subQuery->getQuery()));

			$this->contactDealPairs = $query->exec()->fetchAll();
		}

		return $this->contactDealPairs;
	}

	private function getDealInfo(array $dealIds): array
	{
		if ($this->dealInfoMap === null)
		{
			if (empty($dealIds))
			{
				$this->dealInfoMap = [];

				return [];
			}

			$rows = DealTable::query()
				->setSelect(['ID', 'ASSIGNED_BY_ID', 'DATE_CREATE'])
				->whereIn('ID', $dealIds)
				->exec()
				->fetchAll()
			;

			$this->dealInfoMap = [];
			foreach ($rows as $row)
			{
				$this->dealInfoMap[(int)$row['ID']] = [
					'ID' => (int)$row['ID'],
					'ASSIGNED_BY_ID' => (int)$row['ASSIGNED_BY_ID'],
					'DATE_CREATE' => $row['DATE_CREATE'],
				];
			}
		}

		return $this->dealInfoMap;
	}

	private function findLatestDealByCompanies(array $dealEntityIds): array
	{
		$rows = $this->getCompanyDealRows($dealEntityIds);

		$result = [];
		foreach ($rows as $row)
		{
			$companyId = (int)$row[Item::FIELD_NAME_COMPANY_ID];
			$deal = [
				'ID' => (int)$row['ID'],
				'ASSIGNED_BY_ID' => (int)$row['ASSIGNED_BY_ID'],
				'DATE_CREATE' => $row['DATE_CREATE'],
			];

			if (!isset($result[$companyId]) || $this->isNewerDeal($deal, $result[$companyId]))
			{
				$result[$companyId] = $deal;
			}
		}

		return $result;
	}

	private function getCompanyDealRows(array $dealEntityIds): array
	{
		if (empty($dealEntityIds))
		{
			return [];
		}

		if ($this->companyDealRows === null)
		{
			$subQuery = DealTable::query()
				->addSelect(Item::FIELD_NAME_COMPANY_ID)
				->whereIn('ID', $dealEntityIds)
				->where(Item::FIELD_NAME_COMPANY_ID, '>', 0)
			;

			$query = DealTable::query();
			$query->setSelect(['ID', Item::FIELD_NAME_COMPANY_ID, 'ASSIGNED_BY_ID', 'DATE_CREATE']);
			$query->whereIn(Item::FIELD_NAME_COMPANY_ID, new SqlExpression($subQuery->getQuery()));

			$this->companyDealRows = $query->exec()->fetchAll();
		}

		return $this->companyDealRows;
	}

	private function getAssignmentIdsForClients(array $clientEntityIds): array
	{
		if ($this->entityTypeId === CCrmOwnerType::Contact)
		{
			$fieldName = Item::FIELD_NAME_CONTACT_ID;
		}
		else
		{
			$fieldName = Item::FIELD_NAME_COMPANY_ID;
		}

		$query = new Query(DealTable::getEntity());
		$referenceField = new Reference(
			'D2',
			DealTable::class,
			Join::on('this.' . $fieldName, 'ref.' . $fieldName)
				->where(
					Query::filter()
						->logic('OR')
						->where('ref.DATE_CREATE', '<', new ColumnExpression('this.DATE_CREATE'))
						->where(
							Query::filter()
								->where('ref.DATE_CREATE', new ColumnExpression('this.DATE_CREATE'))
								->where('ref.ID', '<', new ColumnExpression('this.ID')),
						),
				),
			[
				'join_type' => 'LEFT',
			],
		);
		$query->registerRuntimeField('D2', $referenceField);
		$query->setSelect([$fieldName, 'ASSIGNED_BY_ID']);

		if ($this->entityTypeId === CCrmOwnerType::Contact)
		{
			$subQuery = DealContactTable::query()
				->addSelect('DEAL_ID')
				->whereIn(Item::FIELD_NAME_CONTACT_ID, $clientEntityIds)
			;
			$query->setFilter(['@D2.ID' => new SqlExpression($subQuery->getQuery())]);
		}
		else
		{
			$query->setFilter(['=D2.COMPANY_ID' => $clientEntityIds]);
		}

		$lastDealsQueryResult = $query->exec()->fetchAll();
		$clientWithLastDeals = [];
		foreach ($lastDealsQueryResult as $item)
		{
			$assignmentUserId = isset($item['ASSIGNED_BY_ID']) ? (int)$item['ASSIGNED_BY_ID'] : null;
			$clientWithLastDeals[$item[$fieldName]] = $assignmentUserId;
		}

		return $clientWithLastDeals;
	}

	private function isNewerDeal(array $candidate, array $current): bool
	{
		$candidateTimestamp = $candidate['DATE_CREATE']->getTimestamp();
		$currentTimestamp = $current['DATE_CREATE']->getTimestamp();

		if ($candidateTimestamp !== $currentTimestamp)
		{
			return $candidateTimestamp > $currentTimestamp;
		}

		return $candidate['ID'] > $current['ID'];
	}

	private function check(array $clientEntityIds): bool
	{
		if (empty($clientEntityIds))
		{
			return false;
		}

		return in_array($this->entityTypeId, self::AVAILABLE_ENTITY_TYPES, true);
	}
}
