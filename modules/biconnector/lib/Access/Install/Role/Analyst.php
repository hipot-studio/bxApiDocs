<?php

namespace Bitrix\BIConnector\Access\Install\Role;

use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Superset\MarketDashboardManager;
use Bitrix\BIConnector\Superset\Scope\ScopeService;
use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\Loader;

class Analyst extends Base
{
	public function getPermissions(): array
	{
		return [
			PermissionDictionary::BIC_ACCESS,
			PermissionDictionary::BIC_SETTINGS_ACCESS,
			PermissionDictionary::BIC_DASHBOARD_TAG_MODIFY,
			PermissionDictionary::BIC_EXTERNAL_DASHBOARD_CONFIG,
		];
	}

	public function getDefaultGroupPermissions(): array
	{
		$result = [];
		foreach (ScopeService::getSystemGroupCode() as $groupCode)
		{
			$groupPermissions = [
				PermissionDictionary::BIC_DASHBOARD_VIEW,
				PermissionDictionary::BIC_DASHBOARD_EDIT,
				PermissionDictionary::BIC_DASHBOARD_DELETE,
				PermissionDictionary::BIC_DASHBOARD_COPY,
			];

			if (MarketDashboardManager::getInstance()->isExportEnabled())
			{
				$groupPermissions[] = PermissionDictionary::BIC_DASHBOARD_EXPORT;
			}

			$result[$groupCode] = $groupPermissions;
		}

		return $result;
	}

	protected function getRelationUserGroups(): array
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return [];
		}

		if ($this->isNewPortal)
		{
			return [AccessCode::ACCESS_EMPLOYEE . '0'];
		}

		$groups = [];
		$dashboards = SupersetDashboardTable::getList([
			'select' => ['CREATED_BY_ID'],
			'group' => ['CREATED_BY_ID'],
		]);

		while ($dashboard = $dashboards->fetch())
		{
			if (!empty($dashboard['CREATED_BY_ID']))
			{
				$groups[] = "U{$dashboard['CREATED_BY_ID']}";
			}
		}

		return $groups;
	}
}
