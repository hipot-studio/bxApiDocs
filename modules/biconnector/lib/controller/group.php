<?php

namespace Bitrix\BIConnector\Controller;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Access\Service\DashboardGroupService;
use Bitrix\BIConnector\Integration\Superset\Model;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardGroupTable;
use Bitrix\BIConnector\Superset\ActionFilter\BIConstructorAccess;
use Bitrix\Intranet\ActionFilter\IntranetUser;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class Group extends Controller
{
	/**
	 * @return array
	 */
	protected function getDefaultPreFilters(): array
	{
		$additionalFilters = [
			new BIConstructorAccess(),
		];

		if (Loader::includeModule('intranet'))
		{
			$additionalFilters[] = new IntranetUser();
		}

		return [
			...parent::getDefaultPreFilters(),
			...$additionalFilters,
		];
	}

	public function getPrimaryAutoWiredParameter(): ExactParameter
	{
		return new ExactParameter(
			Model\SupersetDashboardGroup::class,
			'group',
			function($className, $id)
			{
				$group = Model\SupersetDashboardGroupTable::getById($id)->fetchObject();
				if (!$group)
				{
					$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_GROUP_ERROR_NOT_FOUND')));

					return null;
				}

				return $group;
			},
		);
	}

	public function loadSettingsDataAction(): ?array
	{
		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_GROUP_MODIFY))
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_GROUP_ACCESS_ERROR_MODIFY')));

			return null;
		}

		$resultGroups = [];
		$resultDashboards = [];

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
				$resultDashboards[$dashboardId] = [
					'id' => $dashboardId,
					'name' => $dashboard->getTitle(),
					'type' => $dashboard->getType(),
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

			$resultGroups[] = [
				'id' => PermissionDictionary::getDashboardGroupPermissionId($group->getId()),
				'name' => $group->getName(),
				'type' => $group->getType(),
				'dashboardIds' => $group->getDashboards()->getIdList(),
				'scopes' => $groupScopes,
			];
		}

		return [
			'groups' => $resultGroups,
			'dashboards' => $resultDashboards,
			'isNeedShowDeletionWarningPopup' => DashboardGroupService::isNeedShowDeletionWarningPopup(),
		];
	}

	public function deleteAction(Model\SupersetDashboardGroup $group): ?bool
	{
		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_GROUP_MODIFY))
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_GROUP_ACCESS_ERROR_DELETE')));

			return null;
		}

		if ($group->isSystem())
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_GROUP_NOT_ALLOWED_DELETE_SYSTEM')));

			return null;
		}

		$result = $group->delete();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return true;
	}

	public function saveAction(array $group, array $dashboards = []): ?bool
	{
		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_GROUP_MODIFY))
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_GROUP_ACCESS_ERROR_MODIFY')));

			return null;
		}

		$groupInfo = [
			'id' => str_starts_with($group['id'], 'new_')
				? null
				: PermissionDictionary::getDashboardGroupIdFromPermission($group['id']),
			'name' => $group['name'],
		];

		$scopeList = array_column($group['scopes'] ?? [], 'code');
		if (!empty($group['dashboardIds']) && !empty($dashboards))
		{
			$groupDashboards = array_filter($dashboards, function($dashboard) use ($group) {
				return in_array($dashboard['id'], $group['dashboardIds'], true);
			});
		}

		$saveResult = DashboardGroupService::saveGroup($groupInfo, $scopeList, $groupDashboards ?? []);
		if (!$saveResult->isSuccess())
		{
			$this->addErrors($saveResult->getErrors());

			return null;
		}

		return true;
	}
}
