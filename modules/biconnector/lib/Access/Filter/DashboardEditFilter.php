<?php

namespace Bitrix\BIConnector\Access\Filter;

use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardGroupTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\Main\Access\Filter\AbstractAccessFilter;

class DashboardEditFilter extends AbstractAccessFilter
{
	/**
	 * Filter for dashboards.
	 *
	 * @param string $entity ORM entity (table class name) to check values from.
	 * @param array $params Additional filter params. Contains 'action' string from ActionDictionary.
	 *
	 * @return array ORM filter for SupersetDashboardTable.
	 */
	public function getFilter(string $entity, array $params = []): array
	{
		$action = (string)($params['action'] ?? '');
		if (empty($action))
		{
			return ['=ID' => null];
		}

		if ($this->user->isAdmin())
		{
			return [];
		}

		if ($entity === SupersetDashboardTable::class)
		{
			$ids = $this->controller->getAllowedDashboardValue(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT);

			return [
				'=ID' => $ids,
			];
		}
		if ($entity === SupersetDashboardGroupTable::class)
		{
			$allowedGroupIds = $this->controller->getAllowedGroupValue(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT);

			if (!empty($allowedGroupIds))
			{
				return ['=ID' => $allowedGroupIds];
			}
		}

		return ['=ID' => null];
	}
}
