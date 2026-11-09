<?php

namespace Bitrix\BIConnector\Access\Component;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Component\PermissionSection\SectionSet;
use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Access\Service\TableGroupService;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardGroupTable;

/**
 * Data of the groups the sections of the rights screen draw.
 *
 * Assembled by the component at the entry; the matrix is redrawn after a save from the answer of the
 * save itself, so the catalog is never sent a second time.
 */
final class SectionPayload
{
	public function __construct(private readonly SectionSet $sections)
	{
	}

	/**
	 * Keys of both axes are always present and the axis nobody draws stays empty: the template and
	 * the client read them whatever the sections of the person are.
	 *
	 * The catalog of an axis costs a read of its own, so it is asked for only where its section is
	 * drawn: a person without the section gets the empty keys and no query behind them.
	 */
	public function getData(): array
	{
		$data = [
			'NEW_GROUP_PERMISSIONS' => [],
			'DASHBOARD_GROUPS' => [],
			'DASHBOARDS' => [],
			'ACCESSIBLE_GROUP_IDS' => [],
			'NEW_TABLE_GROUP_PERMISSIONS' => [],
			'TABLE_GROUPS' => [],
			'TABLES' => [],
		];

		if ($this->sections->hasSection(PermissionConfig::SECTION_DASHBOARD_GROUP_RIGHTS))
		{
			$data = array_merge($data, $this->getDashboardGroupData());
		}

		if ($this->sections->hasSection(PermissionConfig::SECTION_TABLE_GROUP_RIGHTS))
		{
			$data = array_merge($data, [
				'NEW_TABLE_GROUP_PERMISSIONS' => PermissionDictionary::getNewTableGroupPermissions(),
				'TABLE_GROUPS' => TableGroupService::getGroups(),
				'TABLES' => TableGroupService::getTables(),
			]);
		}

		return $data;
	}

	private function getDashboardGroupData(): array
	{
		$resultGroups = [];
		$resultDashboards = [];
		$accessibleGroupIds = [];

		$allowedGroups = array_flip(
			AccessController::getCurrent()->getAllowedGroupValue(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW),
		);

		$groups = SupersetDashboardGroupTable::getList([
			'select' => ['ID', 'NAME', 'TYPE', 'DASHBOARDS', 'SCOPE', 'DASHBOARD_SCOPES' => 'DASHBOARDS.SCOPE'],
			'cache' => ['ttl' => 3600],
		]);
		while ($group = $groups->fetchObject())
		{
			$groupScopes = [];
			foreach ($group->getScope() as $scope)
			{
				$groupScopes[] = [
					'code' => $scope->getScopeCode(),
					'name' => $scope->getName(),
				];
			}

			foreach ($group->getDashboards() as $dashboard)
			{
				$dashboardId = $dashboard->getId();
				if (isset($resultDashboards[$dashboardId]))
				{
					continue;
				}

				$resultDashboards[$dashboardId] = [
					'id' => $dashboardId,
					'name' => $dashboard->getTitle(),
					'type' => $dashboard->getType(),
					'createdById' => $dashboard->getCreatedById(),
					'scopes' => [],
				];
				foreach ($dashboard->getScope() as $scope)
				{
					$resultDashboards[$dashboardId]['scopes'][] = [
						'code' => $scope->getScopeCode(),
						'name' => $scope->getName(),
					];
				}
			}

			$groupId = PermissionDictionary::getDashboardGroupPermissionId($group->getId());

			if (isset($allowedGroups[$group->getId()]))
			{
				$accessibleGroupIds[] = $groupId;
			}

			$resultGroups[] = [
				'id' => $groupId,
				'name' => $group->getName(),
				'type' => $group->getType(),
				'dashboardIds' => $group->getDashboards()->getIdList(),
				'scopes' => $groupScopes,
			];
		}

		return [
			'NEW_GROUP_PERMISSIONS' => PermissionDictionary::getNewGroupPermissions(),
			'DASHBOARD_GROUPS' => $resultGroups,
			'DASHBOARDS' => $resultDashboards,
			'ACCESSIBLE_GROUP_IDS' => $accessibleGroupIds,
		];
	}
}
