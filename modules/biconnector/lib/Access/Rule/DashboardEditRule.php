<?php

namespace Bitrix\BIConnector\Access\Rule;

use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;

final class DashboardEditRule extends DashboardRule
{
	protected function loadGroupDashboards(array $groupIds, array $additionalFilter = []): array
	{
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
				$item->getType() === SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM
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
}
