<?php

namespace Bitrix\BIConnector\Access\Rule;

use Bitrix\BIConnector\Access\Permission\PermissionDictionary;

abstract class AbstractVariableRule extends BaseRule
{
	protected static array $allowedPermissionGroupIds = [];

	protected static array $groupIds = [];

	/**
	 * Return all group ids of the own axis.
	 *
	 * @return int[]
	 */
	abstract protected static function loadAllGroupIds(): array;

	/**
	 * Return permission id of the own axis by group id.
	 *
	 * @param int $groupId
	 *
	 * @return string
	 */
	abstract protected static function getGroupPermissionId(int $groupId): string;

	/**
	 * Return the key of the memoized group list. One key per axis, not per rule class:
	 * heirs of one axis read the very same list.
	 *
	 * @return string
	 */
	abstract protected static function getGroupCacheKey(): string;

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
		// array_key_exists, not isset: the answer "nothing is allowed" is null, and isset would recount it every call
		if (array_key_exists($cacheKey, static::$allowedPermissionGroupIds))
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
			$groupPermissionId = static::getGroupPermissionId($groupId);
			$groupActions = $this->user->getPermissionMulti($groupPermissionId);
			if (empty($groupActions))
			{
				continue;
			}

			if (
				in_array(PermissionDictionary::VALUE_VARIATION_ALL, $groupActions, true)
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
	 * Return allowed group ids by permission.
	 *
	 * Empty array means "nothing is allowed", not "the rule is not applicable".
	 *
	 * @param array $params
	 *
	 * @return array
	 */
	public function getPermissionAllowedGroupIds(array $params): array
	{
		return $this->getPermissionMultiValues($params) ?? [];
	}

	public function check(array $params): bool
	{
		if ($this->isAbleToSkipChecking())
		{
			return true;
		}

		return !empty($this->getPermissionAllowedGroupIds($params));
	}

	/**
	 * Memoized per axis: a shared key would hand one axis the groups of another,
	 * a key per rule class would load the same list anew for every heir of one axis.
	 *
	 * @return int[]
	 */
	protected static function getAllGroups(): array
	{
		$cacheKey = static::getGroupCacheKey();
		if (isset(static::$groupIds[$cacheKey]))
		{
			return static::$groupIds[$cacheKey];
		}

		static::$groupIds[$cacheKey] = static::loadAllGroupIds();

		return static::$groupIds[$cacheKey];
	}

	/**
	 * Clear the caches declared here. They are shared by every rule based on this one, but a rule with
	 * a cache of its own keeps it private: such a rule overrides this method and clears its own cache too,
	 * and callers of the reset are expected to name that rule, not this base.
	 *
	 * @return void
	 */
	public static function clearCache(): void
	{
		static::$allowedPermissionGroupIds = [];
		static::$groupIds = [];
	}
}
