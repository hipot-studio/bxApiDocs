<?php

namespace Bitrix\BIConnector\Access\Rule;

use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardGroupTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;

class VariableRule extends AbstractVariableRule
{
	private static array $allowedPermissionDashboardIds = [];

	protected static function loadAllGroupIds(): array
	{
		$groups = SupersetDashboardGroupTable::getList([
			'select' => ['ID'],
			'cache' => ['ttl' => 3600],
		]);

		return array_column($groups->fetchAll(), 'ID');
	}

	protected static function getGroupPermissionId(int $groupId): string
	{
		return PermissionDictionary::getDashboardGroupPermissionId($groupId);
	}

	protected static function getGroupCacheKey(): string
	{
		return self::class;
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
			return parent::check($params);
		}

		$checkDashboardIds = (array)($params['value'] ?? []);
		$item = $params['item'] ?? null;
		if ($item instanceof DashboardAccessItem && count($checkDashboardIds) === 1)
		{
			$allowedGroupIds = $this->getPermissionAllowedGroupIds($params);
			$allowedDashboardIds = $this->loadGroupDashboards($allowedGroupIds, ['=ID' => $item->getId()]);

			return empty(array_diff($checkDashboardIds, $allowedDashboardIds));
		}

		$values = $this->getPermissionAllowedDashboardIds($params);

		return empty(array_diff($checkDashboardIds, $values));
	}

	/**
	 * Clear all static caches.
	 *
	 * @return void
	 */
	public static function clearCache(): void
	{
		parent::clearCache();

		static::$allowedPermissionDashboardIds = [];
	}
}
