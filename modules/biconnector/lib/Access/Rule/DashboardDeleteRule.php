<?php

namespace Bitrix\BIConnector\Access\Rule;

use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Superset\SystemDashboardManager;

final class DashboardDeleteRule extends DashboardRule
{
	protected function loadGroupDashboards(array $groupIds, array $additionalFilter = []): array
	{
		$types = [SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM];
		if ($this->user->canDeleteRestApp())
		{
			$types[] = SupersetDashboardTable::DASHBOARD_TYPE_MARKET;

			if (SystemDashboardManager::canDeleteSystemDashboard())
			{
				$types[] = SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM;
			}
		}

		$additionalFilter = array_merge($additionalFilter, ['TYPE' => $types]);

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
			if ($item->getType() === SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM)
			{
				return (
					SystemDashboardManager::canDeleteSystemDashboard()
					&& $this->user->canDeleteRestApp()
				);
			}

			if ($item->getType() === SupersetDashboardTable::DASHBOARD_TYPE_MARKET && !$this->user->canDeleteRestApp())
			{
				return false;
			}
		}

		return parent::check($params);
	}

	protected function isAlwaysAvailableForAdmin(): bool
	{
		return SystemDashboardManager::canDeleteSystemDashboard();
	}
}
