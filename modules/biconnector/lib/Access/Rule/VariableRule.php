<?php

namespace Bitrix\BIConnector\Access\Rule;

use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardGroupTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;

class VariableRule extends BaseRule
{
	private static array $allowedPermissionDashboardIds = [];

	private static array $allowedPermissionGroupIds = [];

	private static ?array $groupIds = null;

	/**
	 * Return allowed group ids by permission.
	 *
	 * @param array $params
	 *
	 * @return array|null
	 */
	public function getPermissionMultiValues(array $params): ?array
	{
		$permissionCode = static::getPermissionCode($params);
		if ($permissionCode === null)
		{
			return null;
		}

		$actionPermissionCode = (int)$permissionCode;

		$cacheKey = static::class . '_' . $this->user->getUserId() . '_' . $actionPermissionCode;
		if (isset(static::$allowedPermissionGroupIds[$cacheKey]))
		{
			return static::$allowedPermissionGroupIds[$cacheKey];
		}
		$allGroupIds = static::getAllGroups();
		if ($this->isAbleToSkipChecking())
		{
			static::$allowedPermissionGroupIds[$cacheKey] = $allGroupIds;

			return $allGroupIds;
		}

		$allowedGroupIds = [];
		foreach ($allGroupIds as $groupId)
		{
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

		$result = !empty($allowedGroupIds) ? $allowedGroupIds : null;
		static::$allowedPermissionGroupIds[$cacheKey] = $result;

		return $result;
	}

	/**
	 * Return allowed dashboard ids by group permission.
	 *
	 * @param array $params
	 *
	 * @return array
	 */
	public function getPermissionAllowedDashboardIds(array $params): array
	{
		$permissionCode = static::getPermissionCode($params);
		if ($permissionCode === null)
		{
			return [];
		}

		$actionPermissionCode = (int)$permissionCode;

		$cacheKey = static::class . '_' . $this->user->getUserId() . '_' . $actionPermissionCode;

		if (isset(static::$allowedPermissionDashboardIds[$cacheKey]))
		{
			return static::$allowedPermissionDashboardIds[$cacheKey];
		}

		if ($this->isAbleToSkipChecking())
		{
			$dashboards = SupersetDashboardTable::getList([
				'select' => ['ID'],
				'cache' => ['ttl' => 3600],
			])
				->fetchAll()
			;

			$result = array_column($dashboards, 'ID');
			static::$allowedPermissionDashboardIds[$cacheKey] = $result;

			return $result;
		}

		$allowedGroupIds = $this->getPermissionAllowedGroupIds($params);
		$result = $this->loadGroupDashboards($allowedGroupIds);
		static::$allowedPermissionDashboardIds[$cacheKey] = $result;

		return $result;
	}

	/**
	 * Return allowed group ids by permission.
	 *
	 * @param array $params
	 *
	 * @return array
	 */
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

	/**
	 * Check if user has permission for given dashboard IDs.
	 *
	 * @param array $params
	 *
	 * @return bool
	 */
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

	private static function getAllGroups(): array
	{
		if (static::$groupIds !== null)
		{
			return static::$groupIds;
		}

		$groups = SupersetDashboardGroupTable::getList([
			'select' => ['ID'],
			'cache' => ['ttl' => 3600],
		]);

		static::$groupIds = array_column($groups->fetchAll(), 'ID');

		return static::$groupIds;
	}

	/**
	 * Clear all static caches.
	 *
	 * @return void
	 */
	public static function clearCache(): void
	{
		static::$allowedPermissionDashboardIds = [];
		static::$allowedPermissionGroupIds = [];
		static::$groupIds = null;
	}
}
