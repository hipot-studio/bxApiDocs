<?php

namespace Bitrix\Sale\Internal\Access;

use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Sale;

final class ShipmentAccess
{
	private ?array $readFilter = null;

	public function __construct(
		private readonly int $userId,
		private readonly string $saleModulePermission,
	)
	{
	}

	public function canRead(int $shipmentId): bool
	{
		if ($this->userId <= 0 || $this->saleModulePermission === 'D')
		{
			return false;
		}

		if (ModuleManager::isModuleInstalled('crm'))
		{
			return Loader::includeModule('crm')
				&& (new Sale\Internal\Integration\Crm\ShipmentAccess($this->userId))->canRead($shipmentId)
			;
		}

		return (bool)Sale\Internals\ShipmentTable::getList([
			'select' => ['ID'],
			'filter' => [
				['=ID' => $shipmentId],
				$this->getReadFilter(),
			],
			'limit' => 1,
		])->fetch();
	}

	public function getReadFilter(): array
	{
		if ($this->readFilter !== null)
		{
			return $this->readFilter;
		}

		if ($this->userId <= 0 || $this->saleModulePermission === 'D')
		{
			return $this->readFilter = ['=ID' => 0];
		}

		if (ModuleManager::isModuleInstalled('crm'))
		{
			return $this->readFilter = Loader::includeModule('crm')
				? (new Sale\Internal\Integration\Crm\ShipmentAccess($this->userId))->getReadFilter()
				: ['=ID' => 0]
			;
		}

		return $this->readFilter = $this->getSaleReadFilter();
	}

	private function getSaleReadFilter(): array
	{
		if ($this->saleModulePermission >= 'W')
		{
			return [];
		}

		$ownerFilter = ['=ORDER.USER_ID' => $this->userId];
		$statuses = Sale\OrderStatus::getStatusesUserCanDoOperations($this->userId, ['view']);
		if ($statuses === [])
		{
			return $ownerFilter;
		}

		$managerFilter = ['@ORDER.STATUS_ID' => array_values($statuses)];

		if ($this->saleModulePermission === 'U')
		{
			$sites = [];
			$accessibleSites = \CSaleGroupAccessToSite::GetList(
				[],
				['GROUP_ID' => \CUser::GetUserGroup($this->userId)],
				false,
				false,
				['SITE_ID'],
			);
			while ($site = $accessibleSites->Fetch())
			{
				$sites[] = $site['SITE_ID'];
			}
			if ($sites === [])
			{
				return $ownerFilter;
			}

			$managerFilter['@ORDER.LID'] = $sites;
		}
		elseif ($this->saleModulePermission === 'P')
		{
			$companyFilter = [
				'LOGIC' => 'OR',
				'=ORDER.RESPONSIBLE_ID' => $this->userId,
			];
			$companies = Sale\Services\Company\Manager::getUserCompanyList($this->userId);
			if ($companies !== [])
			{
				$companyFilter['@ORDER.COMPANY_ID'] = $companies;
			}
			$managerFilter[] = $companyFilter;
		}

		return [
			'LOGIC' => 'OR',
			$ownerFilter,
			$managerFilter,
		];
	}
}
