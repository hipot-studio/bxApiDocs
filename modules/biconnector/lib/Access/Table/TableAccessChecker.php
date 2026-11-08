<?php

namespace Bitrix\BIConnector\Access\Table;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Service\TableGroupMembershipService;
use Bitrix\BIConnector\Access\TableGroup\TableGroupTable;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\Main\ArgumentException;

/**
 * Answers whether a user may perform an action of the table axis with one table.
 *
 * The access rule answers by groups only, so the right on a table is derived here: the action is allowed
 * when at least one group the table is bound to is allowed to the user. A table without bindings belongs
 * to no group and is refused to everybody, an administrator included.
 *
 * Answers are kept in the instance, one instance per user: nothing is shared between users.
 */
final class TableAccessChecker
{
	private const ACTIONS = [
		ActionDictionary::ACTION_BIC_TABLE_VIEW,
		ActionDictionary::ACTION_BIC_TABLE_EDIT,
		ActionDictionary::ACTION_BIC_TABLE_EXPORT,
	];

	private ?bool $isRestricted = null;

	/** @var array<string, int[]> */
	private array $allowedGroupIds = [];

	/** @var int[]|null */
	private ?array $existingGroupIds = null;

	public function __construct(private readonly ?int $userId = null)
	{
	}

	/**
	 * @return bool False while the table axis is off: then every action with every table is allowed.
	 */
	public function isRestricted(): bool
	{
		$this->isRestricted ??= Feature::isTablePermissionsEnabled();

		return $this->isRestricted;
	}

	/**
	 * @param string $action One of the table actions of ActionDictionary.
	 *
	 * @return int[] Groups in which the user may perform the action.
	 *
	 * @throws ArgumentException
	 */
	public function getAllowedGroupIds(string $action): array
	{
		self::validateAction($action);

		if (!isset($this->allowedGroupIds[$action]))
		{
			$groupIds = $this->getAccessController()->getAllowedGroupValue($action) ?? [];
			$this->allowedGroupIds[$action] = array_values(array_unique(array_map('intval', $groupIds)));
		}

		return $this->allowedGroupIds[$action];
	}

	/**
	 * @param string $action
	 * @param int $groupId
	 *
	 * @return bool
	 *
	 * @throws ArgumentException
	 */
	public function canUseGroup(string $action, int $groupId): bool
	{
		self::validateAction($action);

		if (!$this->isRestricted())
		{
			return true;
		}

		return
			in_array($groupId, $this->getExistingGroupIds(), true)
			&& in_array($groupId, $this->getAllowedGroupIds($action), true)
		;
	}

	/**
	 * @param string $action
	 * @param string $tableCode
	 *
	 * @return bool True when the action is allowed in at least one group of the table.
	 *
	 * @throws ArgumentException
	 */
	public function canDo(string $action, string $tableCode): bool
	{
		self::validateAction($action);

		if (!$this->isRestricted())
		{
			return true;
		}

		$tableGroupIds = TableGroupMembershipService::getGroupIdsByTableCode($tableCode);

		return !empty(array_intersect($tableGroupIds, $this->getAllowedGroupIds($action)));
	}

	/**
	 * For a table that is put into several groups at once: allowed in one of them is not enough.
	 *
	 * @param string $action
	 * @param int[] $groupIds
	 *
	 * @return bool True when the set is not empty and the action is allowed in every group of it.
	 *
	 * @throws ArgumentException
	 */
	public function canUseEveryGroup(string $action, array $groupIds): bool
	{
		self::validateAction($action);

		if (!$this->isRestricted())
		{
			return true;
		}

		if (empty($groupIds))
		{
			return false;
		}

		foreach ($groupIds as $groupId)
		{
			if (!$this->canUseGroup($action, (int)$groupId))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * A group the table leaves and a group it enters both need editing; a group that stays needs nothing.
	 *
	 * @param string $tableCode
	 * @param int[] $groupIds The whole new set of groups of the table.
	 *
	 * @return bool
	 *
	 * @throws ArgumentException
	 */
	public function canChangeTableGroups(string $tableCode, array $groupIds): bool
	{
		if (!$this->isRestricted())
		{
			return true;
		}

		if (empty($groupIds) || !$this->canDo(ActionDictionary::ACTION_BIC_TABLE_EDIT, $tableCode))
		{
			return false;
		}

		$groupIds = array_values(array_unique(array_map('intval', $groupIds)));
		$tableGroupIds = TableGroupMembershipService::getGroupIdsByTableCode($tableCode);
		$changedGroupIds = array_merge(
			array_diff($tableGroupIds, $groupIds),
			array_diff($groupIds, $tableGroupIds),
		);

		return
			empty($changedGroupIds)
			|| $this->canUseEveryGroup(ActionDictionary::ACTION_BIC_TABLE_EDIT, $changedGroupIds)
		;
	}

	/**
	 * Creation drops every binding of the code outside the system groups, which is a move of the code out of
	 * them: each custom group the code is bound to has to allow editing. Bindings to system groups are left
	 * to the reserved code check of TableGroupService.
	 *
	 * @param string $tableCode Code of a table that is being created.
	 *
	 * @return bool
	 */
	public function canTakeTableCode(string $tableCode): bool
	{
		if (!$this->isRestricted())
		{
			return true;
		}

		$tableGroupIds = TableGroupMembershipService::getGroupIdsByTableCode($tableCode);
		if (empty($tableGroupIds))
		{
			return true;
		}

		$rows = TableGroupTable::getList([
			'select' => ['ID'],
			'filter' => [
				'@ID' => $tableGroupIds,
				'=TYPE' => TableGroupTable::GROUP_TYPE_CUSTOM,
			],
		])->fetchAll();
		$customGroupIds = array_map('intval', array_column($rows, 'ID'));

		return empty(array_diff($customGroupIds, $this->getAllowedGroupIds(ActionDictionary::ACTION_BIC_TABLE_EDIT)));
	}

	private function getAccessController(): AccessController
	{
		if ($this->userId === null)
		{
			return AccessController::getCurrent();
		}

		return AccessController::getInstance($this->userId);
	}

	/**
	 * @return int[]
	 */
	private function getExistingGroupIds(): array
	{
		if ($this->existingGroupIds === null)
		{
			$rows = TableGroupTable::getList([
				'select' => ['ID'],
				'cache' => ['ttl' => 3600],
			])->fetchAll();

			$this->existingGroupIds = array_map('intval', array_column($rows, 'ID'));
		}

		return $this->existingGroupIds;
	}

	/**
	 * @param string $action
	 *
	 * @return void
	 *
	 * @throws ArgumentException
	 */
	private static function validateAction(string $action): void
	{
		if (!in_array($action, self::ACTIONS, true))
		{
			throw new ArgumentException("Action '{$action}' is not an action of the table axis", 'action');
		}
	}
}
