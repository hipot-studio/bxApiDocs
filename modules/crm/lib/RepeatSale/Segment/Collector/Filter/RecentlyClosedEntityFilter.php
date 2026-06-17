<?php

namespace Bitrix\Crm\RepeatSale\Segment\Collector\Filter;

use Bitrix\Crm\Binding\DealContactTable;
use Bitrix\Crm\DealTable;
use Bitrix\Main\Type\DateTime;
use CCrmOwnerType;

final class RecentlyClosedEntityFilter
{
	private const MAX_DAYS = 365;

	private DateTime $closedAfterDate;

	public function filter(array $ids, int $entityTypeId, int $minimumDaysAfterLastClosedEntity): array
	{
		if ($minimumDaysAfterLastClosedEntity <= 0 || empty($ids))
		{
			return $ids;
		}

		$minimumDaysAfterLastClosedEntity = min($minimumDaysAfterLastClosedEntity, self::MAX_DAYS);
		$this->closedAfterDate = (new DateTime())->add('- ' . $minimumDaysAfterLastClosedEntity . ' days');

		return match ($entityTypeId)
		{
			CCrmOwnerType::Company => $this->filterCompanies($ids),
			CCrmOwnerType::Contact => $this->filterContacts($ids),
			CCrmOwnerType::Deal => $this->filterDeals($ids),
			default => $ids,
		};
	}

	private function filterCompanies(array $companyIds): array
	{
		$companiesWithRecentDeals = DealTable::query()
			->addSelect('COMPANY_ID')
			->setDistinct()
			->whereIn('COMPANY_ID', $companyIds)
			->where('CLOSED', 'Y')
			->where('CLOSEDATE', '>', $this->closedAfterDate)
			->exec()
			->fetchAll()
		;

		$excludeIds = array_column($companiesWithRecentDeals, 'COMPANY_ID');

		return array_diff($companyIds, $excludeIds);
	}

	private function filterContacts(array $contactIds): array
	{
		$recentlyClosedDeals = DealTable::query()
			->addSelect('ID')
			->whereIn('CONTACT_ID', $contactIds)
			->where('CLOSED', 'Y')
			->where('CLOSEDATE', '>', $this->closedAfterDate)
			->exec()
			->fetchAll()
		;

		if (empty($recentlyClosedDeals))
		{
			return $contactIds;
		}

		$recentlyClosedDealIds = array_column($recentlyClosedDeals, 'ID');

		$contactsWithRecentDeals = DealContactTable::query()
			->addSelect('CONTACT_ID')
			->setDistinct()
			->whereIn('DEAL_ID', $recentlyClosedDealIds)
			->exec()
			->fetchAll()
		;

		$excludeIds = array_column($contactsWithRecentDeals, 'CONTACT_ID');

		return array_diff($contactIds, $excludeIds);
	}

	private function filterDeals(array $dealIds): array
	{
		$dealsToExclude = array_merge(
			$this->findDealsWithRecentlyClosedCompany($dealIds),
			$this->findDealsWithRecentlyClosedContact($dealIds),
		);

		return array_diff($dealIds, array_unique($dealsToExclude));
	}

	private function findDealsWithRecentlyClosedCompany(array $dealIds): array
	{
		$dealCompanyRows = DealTable::query()
			->setSelect(['ID', 'COMPANY_ID'])
			->whereIn('ID', $dealIds)
			->where('COMPANY_ID', '>', 0)
			->exec()
			->fetchAll()
		;

		if (empty($dealCompanyRows))
		{
			return [];
		}

		$companyToDealMap = [];
		foreach ($dealCompanyRows as $row)
		{
			$companyToDealMap[(int)$row['COMPANY_ID']][] = (int)$row['ID'];
		}

		$companiesWithRecentDeals = DealTable::query()
			->addSelect('COMPANY_ID')
			->setDistinct()
			->whereIn('COMPANY_ID', array_keys($companyToDealMap))
			->where('CLOSED', 'Y')
			->where('CLOSEDATE', '>', $this->closedAfterDate)
			->exec()
			->fetchAll()
		;

		$result = [];
		foreach ($companiesWithRecentDeals as $row)
		{
			array_push($result, ...($companyToDealMap[(int)$row['COMPANY_ID']] ?? []));
		}

		return $result;
	}

	private function findDealsWithRecentlyClosedContact(array $dealIds): array
	{
		$dealContactRows = DealContactTable::query()
			->setSelect(['DEAL_ID', 'CONTACT_ID'])
			->whereIn('DEAL_ID', $dealIds)
			->exec()
			->fetchAll()
		;

		if (empty($dealContactRows))
		{
			return [];
		}

		$contactToDealMap = [];
		foreach ($dealContactRows as $row)
		{
			$contactToDealMap[(int)$row['CONTACT_ID']][] = (int)$row['DEAL_ID'];
		}

		$recentlyClosedDeals = DealTable::query()
			->addSelect('ID')
			->whereIn('CONTACT_ID', array_keys($contactToDealMap))
			->where('CLOSED', 'Y')
			->where('CLOSEDATE', '>', $this->closedAfterDate)
			->exec()
			->fetchAll()
		;

		if (empty($recentlyClosedDeals))
		{
			return [];
		}

		$recentlyClosedDealIds = array_column($recentlyClosedDeals, 'ID');

		$contactsWithRecentDeals = DealContactTable::query()
			->addSelect('CONTACT_ID')
			->setDistinct()
			->whereIn('DEAL_ID', $recentlyClosedDealIds)
			->exec()
			->fetchAll()
		;

		$result = [];
		foreach ($contactsWithRecentDeals as $row)
		{
			array_push($result, ...($contactToDealMap[(int)$row['CONTACT_ID']] ?? []));
		}

		return $result;
	}
}
