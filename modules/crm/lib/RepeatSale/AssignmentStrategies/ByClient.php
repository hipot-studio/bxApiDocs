<?php

namespace Bitrix\Crm\RepeatSale\AssignmentStrategies;

use Bitrix\Crm\Binding\DealContactTable;
use Bitrix\Crm\DealTable;
use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;
use CCrmOwnerType;

final class ByClient extends Base
{
	private const AVAILABLE_ENTITY_TYPES = [
		CCrmOwnerType::Contact,
		CCrmOwnerType::Company,
		CCrmOwnerType::Deal,
	];

	private ?array $clientAssignmentIds = [];

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
				: $this->getClientAssignedByIds($this->entityTypeId, $entityIds)
			;

			$this->clientAssignmentIds[$this->entityTypeId] = $assignmentIds;
		}

		return $this->clientAssignmentIds[$this->entityTypeId];
	}

	private function getAssignmentIdsForDeals(array $dealEntityIds): array
	{
		if (empty($dealEntityIds))
		{
			return [];
		}

		$contactRows = DealContactTable::query()
			->setSelect(['DEAL_ID', Item::FIELD_NAME_CONTACT_ID])
			->whereIn('DEAL_ID', $dealEntityIds)
			->setOrder(['IS_PRIMARY' => 'DESC'])
			->exec()
			->fetchAll()
		;

		$dealContacts = [];
		$allContactIds = [];
		foreach ($contactRows as $row)
		{
			$contactId = (int)$row[Item::FIELD_NAME_CONTACT_ID];
			$dealContacts[(int)$row['DEAL_ID']][] = $contactId;
			$allContactIds[$contactId] = true;
		}

		$companyRows = DealTable::query()
			->setSelect(['ID', Item::FIELD_NAME_COMPANY_ID])
			->whereIn('ID', $dealEntityIds)
			->where(Item::FIELD_NAME_COMPANY_ID, '>', 0)
			->exec()
			->fetchAll()
		;

		$dealCompanies = [];
		$allCompanyIds = [];
		foreach ($companyRows as $row)
		{
			$companyId = (int)$row[Item::FIELD_NAME_COMPANY_ID];
			$dealCompanies[(int)$row['ID']] = $companyId;
			$allCompanyIds[$companyId] = true;
		}

		$allContactIds = array_keys($allContactIds);
		$allCompanyIds = array_keys($allCompanyIds);

		if (empty($allContactIds) && empty($allCompanyIds))
		{
			return [];
		}

		$contactAssigned = $this->getClientAssignedByIds(CCrmOwnerType::Contact, $allContactIds);
		$companyAssigned = $this->getClientAssignedByIds(CCrmOwnerType::Company, $allCompanyIds);

		$result = [];
		foreach ($dealEntityIds as $dealId)
		{
			foreach ($dealContacts[$dealId] ?? [] as $contactId)
			{
				if (isset($contactAssigned[$contactId]))
				{
					$result[$dealId] = $contactAssigned[$contactId];

					break;
				}
			}

			if (isset($result[$dealId]))
			{
				continue;
			}

			$companyId = $dealCompanies[$dealId] ?? null;
			if ($companyId !== null && isset($companyAssigned[$companyId]))
			{
				$result[$dealId] = $companyAssigned[$companyId];
			}
		}

		return $result;
	}

	private function getClientAssignedByIds(int $entityTypeId, array $entityIds): array
	{
		if (empty($entityIds))
		{
			return [];
		}

		$queryResult = Container::getInstance()
			->getFactory($entityTypeId)
			?->getItems([
				'select' => [
					Item::FIELD_NAME_ID,
					Item::FIELD_NAME_ASSIGNED,
				],
				'filter' => [
					'@' . Item::FIELD_NAME_ID => $entityIds,
				],
			])
		;

		$result = [];
		foreach ($queryResult ?? [] as $item)
		{
			$assignmentUserId = isset($item[Item::FIELD_NAME_ASSIGNED]) ? (int)$item[Item::FIELD_NAME_ASSIGNED] : null;
			$result[$item[Item::FIELD_NAME_ID]] = $assignmentUserId;
		}

		return $result;
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
