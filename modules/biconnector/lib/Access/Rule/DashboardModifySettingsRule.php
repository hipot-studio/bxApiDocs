<?php

namespace Bitrix\BIConnector\Access\Rule;

use Bitrix\BIConnector\Access\ActionDictionary;

final class DashboardModifySettingsRule extends DashboardRule
{
	public function getPermissionMultiValues(array $params): ?array
	{
		return parent::getPermissionMultiValues($this->substituteWithEditAction($params));
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
		return parent::check($this->substituteWithEditAction($params));
	}

	private function substituteWithEditAction(array $params): array
	{
		$editAction = ActionDictionary::ACTION_BIC_DASHBOARD_EDIT;
		$params['action'] = $editAction;
		$params['permissionId'] = $this->getPermissionId($editAction);

		return $params;
	}
}
