<?php

namespace Bitrix\BIConnector\Access\Rule;

use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;

final class DashboardViewRule extends DashboardRule
{
	protected function loadGroupDashboards(array $groupIds, array $additionalFilter = []): array
	{
		$nonDraftFilter = array_merge($additionalFilter, ['!=STATUS' => SupersetDashboardTable::DASHBOARD_STATUS_DRAFT]);
		$dashboardIds = parent::loadGroupDashboards($groupIds, $nonDraftFilter);

		$editAction = ActionDictionary::ACTION_BIC_DASHBOARD_EDIT;
		$editParams = [
			'action' => $editAction,
			'permissionId' => $this->getPermissionId($editAction),
		];
		$editGroupIds = $this->getPermissionAllowedGroupIds($editParams);

		$allowedEditGroupIds = array_intersect($groupIds, $editGroupIds);
		if (!empty($allowedEditGroupIds))
		{
			$draftFilter = array_merge($additionalFilter, ['=STATUS' => SupersetDashboardTable::DASHBOARD_STATUS_DRAFT]);
			$draftDashboardIds = parent::loadGroupDashboards($allowedEditGroupIds, $draftFilter);
			$dashboardIds = array_unique(array_merge($dashboardIds, $draftDashboardIds));
		}

		return $dashboardIds;
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
		if ($item instanceof DashboardAccessItem && $item->getStatus() === SupersetDashboardTable::DASHBOARD_STATUS_DRAFT)
		{
			$editAction = ActionDictionary::ACTION_BIC_DASHBOARD_EDIT;
			$params['action'] = $editAction;
			$params['permissionId'] = $this->getPermissionId($editAction);
		}

		return parent::check($params);
	}
}
