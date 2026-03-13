<?php

namespace Bitrix\BIConnector\Access\Rule;

use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Superset\MarketDashboardManager;

final class DashboardExportRule extends DashboardRule
{
	public function getPermissionMultiValues(array $params): ?array
	{
		if (!$this->isExportEnabled())
		{
			return [];
		}

		return parent::getPermissionMultiValues($params);
	}

	protected function loadGroupDashboards(array $groupIds, array $additionalFilter = []): array
	{
		if (!$this->isExportEnabled())
		{
			return [];
		}

		$additionalFilter = array_merge($additionalFilter, ['TYPE' => SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM]);

		return parent::loadGroupDashboards($groupIds, $additionalFilter);
	}

	/**
	 * Check access permission.
	 *
	 * @param array $params
	 *
	 * @return bool
	 */
	public function check(array $params): bool
	{
		$item = $params['item'] ?? null;
		if ($item instanceof DashboardAccessItem)
		{
			if (
				!$this->isExportEnabled()
				|| $item->getType() === SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM
				|| $item->getType() === SupersetDashboardTable::DASHBOARD_TYPE_MARKET
			)
			{
				return false;
			}
		}

		return parent::check($params);
	}

	protected function isAlwaysAvailableForAdmin(): bool
	{
		return false;
	}

	private function isExportEnabled(): bool
	{
		return MarketDashboardManager::getInstance()->isExportEnabled();
	}
}
