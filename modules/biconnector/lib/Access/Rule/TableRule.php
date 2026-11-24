<?php

namespace Bitrix\BIConnector\Access\Rule;

use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\TableAccessItem;
use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Access\Service\TableGroupMembershipService;
use Bitrix\BIConnector\Access\TableGroup\TableGroupTable;
use Bitrix\Main\Access\AccessibleItem;

class TableRule extends AbstractVariableRule
{
	/** @var array<string, array{codes: string[], index: array<string, true>}> */
	private static array $allowedTableCodes = [];

	protected static function loadAllGroupIds(): array
	{
		$groups = TableGroupTable::getList([
			'select' => ['ID'],
			'cache' => ['ttl' => 3600],
		]);

		return array_column($groups->fetchAll(), 'ID');
	}

	protected static function getGroupPermissionId(int $groupId): string
	{
		return PermissionDictionary::getTableGroupPermissionId($groupId);
	}

	protected static function getGroupCacheKey(): string
	{
		return self::class;
	}

	/**
	 * A question about a single table is answered by the list of allowed tables. That list already lets
	 * the administrator through to every table with a group, so the early pass of the base rule is not
	 * taken here: it would open a table without groups as well.
	 *
	 * @param AccessibleItem|null $item
	 * @param null $params
	 *
	 * @return bool
	 */
	public function execute(?AccessibleItem $item = null, $params = null): bool
	{
		if ($item === null)
		{
			return parent::execute($item, $params);
		}

		if (empty($params['action']))
		{
			return false;
		}

		$permissionId = $this->getPermissionId($params['action']);
		if (empty($permissionId))
		{
			return false;
		}

		$params['item'] = $item;
		$params['value'] = $item->getId();
		$params['permissionId'] = $permissionId;

		return $this->check($params);
	}

	/**
	 * Without an item the axis answers by groups: whether any group is allowed. A table is answered by its
	 * code; any other item is refused, the group answer being wider than the question.
	 *
	 * @param array $params
	 *
	 * @return bool
	 */
	public function check(array $params): bool
	{
		$item = $params['item'] ?? null;
		if ($item instanceof TableAccessItem)
		{
			$tableCode = $item->getCode();

			return $tableCode !== '' && isset($this->getAllowedTableCodesEntry($params)['index'][$tableCode]);
		}

		if ($item !== null)
		{
			return false;
		}

		return parent::check($params);
	}

	/**
	 * Codes of the tables allowed for the operation of the action.
	 *
	 * A cell of a role replaces the group rights of that role for its table: "nothing" or a broken cell
	 * closes the table, a cell with view opens it even in a group the role has no right to. The answers
	 * of the roles are united. A table without groups is closed to everybody.
	 *
	 * @param array $params
	 *
	 * @return string[]
	 */
	public function getPermissionAllowedTableCodes(array $params): array
	{
		return $this->getAllowedTableCodesEntry($params)['codes'];
	}

	/**
	 * @param array $params
	 *
	 * @return array{codes: string[], index: array<string, true>}
	 */
	private function getAllowedTableCodesEntry(array $params): array
	{
		$permissionCode = static::getPermissionCode($params);
		if ($permissionCode === null)
		{
			return ['codes' => [], 'index' => []];
		}

		$operation = (int)$permissionCode;
		$cacheKey = static::class . '_' . $this->user->getUserId() . '_' . $operation;
		if (array_key_exists($cacheKey, self::$allowedTableCodes))
		{
			return self::$allowedTableCodes[$cacheKey];
		}

		$tableCodes = $this->isAbleToSkipChecking()
			? TableGroupMembershipService::getTableCodesByGroupIds(static::getAllGroups())
			: $this->collectRoleTableCodes($operation)
		;

		$index = [];
		foreach ($tableCodes as $tableCode)
		{
			$index[(string)$tableCode] = true;
		}

		self::$allowedTableCodes[$cacheKey] = [
			'codes' => array_map('strval', array_keys($index)),
			'index' => $index,
		];

		return self::$allowedTableCodes[$cacheKey];
	}

	/**
	 * @param int $operation
	 *
	 * @return string[]
	 */
	private function collectRoleTableCodes(int $operation): array
	{
		$existingGroupIds = [];
		foreach (static::getAllGroups() as $groupId)
		{
			$existingGroupIds[(int)$groupId] = true;
		}

		$tableCodes = [];
		foreach ($this->user->getTableRightsByRole() as $roleRights)
		{
			$allowedGroupIds = [];
			foreach ($roleRights['groups'] as $groupId => $values)
			{
				if (
					isset($existingGroupIds[$groupId])
					&& (
						in_array(PermissionDictionary::VALUE_VARIATION_ALL, $values, true)
						|| in_array($operation, $values, true)
					)
				)
				{
					$allowedGroupIds[] = $groupId;
				}
			}

			$overriddenCodes = [];
			foreach ($roleRights['cells'] as $tableCode => $values)
			{
				$tableCode = (string)$tableCode;
				$overriddenCodes[$tableCode] = true;

				if (
					self::isCellGranting($values, $operation)
					&& !empty(TableGroupMembershipService::getGroupIdsByTableCode($tableCode))
				)
				{
					$tableCodes[$tableCode] = $tableCode;
				}
			}

			foreach (TableGroupMembershipService::getTableCodesByGroupIds($allowedGroupIds) as $tableCode)
			{
				$tableCode = (string)$tableCode;
				if (!isset($overriddenCodes[$tableCode]))
				{
					$tableCodes[$tableCode] = $tableCode;
				}
			}
		}

		return array_values($tableCodes);
	}

	/**
	 * A cell grants an operation only when it holds view and nothing but the operations of the axis.
	 *
	 * @param int[] $values
	 * @param int $operation
	 *
	 * @return bool
	 */
	private static function isCellGranting(array $values, int $operation): bool
	{
		$operations = PermissionDictionary::getTablePermissionCodes();
		foreach ($values as $value)
		{
			if (!in_array($value, $operations, true))
			{
				return false;
			}
		}

		return
			in_array(PermissionDictionary::BIC_TABLE_VIEW, $values, true)
			&& in_array($operation, $values, true)
		;
	}

	protected static function getPermissionCode(array $params): ?string
	{
		$permissionCode = ActionDictionary::getTablePermissionsMap()[$params['action']] ?? null;

		if (!$permissionCode)
		{
			return null;
		}

		return (string)$permissionCode;
	}

	public static function clearCache(): void
	{
		parent::clearCache();

		self::$allowedTableCodes = [];
	}
}
