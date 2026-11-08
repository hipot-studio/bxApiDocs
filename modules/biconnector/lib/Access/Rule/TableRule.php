<?php

namespace Bitrix\BIConnector\Access\Rule;

use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Access\TableGroup\TableGroupTable;

class TableRule extends AbstractVariableRule
{
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
	 * The axis resolves access by groups only: access of a single table is nobody's answer yet.
	 * An item in the params means the caller asked about that very table, and the group answer is
	 * wider than the question, so it is refused instead of silently allowing the whole axis.
	 *
	 * @param array $params
	 *
	 * @return bool
	 */
	public function check(array $params): bool
	{
		if (($params['item'] ?? null) !== null)
		{
			return false;
		}

		return parent::check($params);
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
}
