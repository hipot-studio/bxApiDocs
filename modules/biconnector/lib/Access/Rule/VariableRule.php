<?php

namespace Bitrix\BIConnector\Access\Rule;

use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardGroupTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;

class VariableRule extends BaseRule
{
	public function getPermissionMultiValues(array $params): ?array
	{
		$groups = SupersetDashboardGroupTable::getList([
			'select' => ['ID'],
			'cache' => ['ttl' => 3600],
		])->fetchAll();

		if ($this->isAbleToSkipChecking())
		{
			return array_column($groups, 'ID');
		}

		$actionPermissionCode = (int)static::getPermissionCode($params);
		$allowedGroupIds = [];

		foreach ($groups as $group)
		{
			$groupId = $group['ID'];
			$groupPermissionId = PermissionDictionary::getDashboardGroupPermissionId($groupId);
			$groupActions = $this->user->getPermissionMulti($groupPermissionId);
			if (empty($groupActions))
			{
				continue;
			}

			if (
				$groupActions[0] === PermissionDictionary::VALUE_VARIATION_ALL
				|| in_array($actionPermissionCode, $groupActions, true)
			)
			{
				$allowedGroupIds[] = $groupId;
			}
		}

		return !empty($allowedGroupIds) ? $allowedGroupIds : null;
	}

	public function getPermissionAllowedDashboardIds(array $params): array
	{
		if ($this->isAbleToSkipChecking())
		{
			$dashboards = SupersetDashboardTable::getList([
				'select' => ['ID'],
				'cache' => ['ttl' => 3600],
			])
				->fetchAll()
			;

			return array_column($dashboards, 'ID');
		}

		$allowedGroupIds = $this->getPermissionAllowedGroupIds($params);

		return $this->loadGroupDashboards($allowedGroupIds);
	}

	public function getPermissionAllowedGroupIds(array $params): array
	{
		return $this->getPermissionMultiValues($params) ?? [];
	}

	protected function loadGroupDashboards(
		array $groupIds,
		array $additionalFilter = [],
	): array
	{
		if (empty($groupIds))
		{
			return [];
		}

		$filter = array_merge(
			['GROUPS.ID' => $groupIds],
			$additionalFilter,
		);

		$dashboardList = SupersetDashboardTable::getList([
			'select' => ['ID'],
			'filter' => $filter,
			'cache' => ['ttl' => 3600],
		])
			->fetchAll()
		;

		return array_unique(
			array_column($dashboardList, 'ID'),
		);
	}

	public function check(array $params): bool
	{
		if ($this->isAbleToSkipChecking())
		{
			return true;
		}

		if (!isset($params['value']))
		{
			return !empty($this->getPermissionAllowedGroupIds($params));
		}

		$values = $this->getPermissionAllowedDashboardIds($params);

		$checkDashboardIds = (array)($params['value'] ?? []);

		return empty(array_diff($checkDashboardIds, $values));
	}
}
