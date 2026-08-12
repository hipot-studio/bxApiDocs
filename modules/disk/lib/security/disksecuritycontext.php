<?php

namespace Bitrix\Disk\Security;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Internals\ObjectPathTable;
use Bitrix\Disk\Internals\RightTable;
use Bitrix\Disk\Internals\SimpleRightTable;
use Bitrix\Disk\RightsManager;
use Bitrix\Disk\Access\AccessCodeEnum;
use Bitrix\Main\UserAccessTable;
use CAccess;

class DiskSecurityContext extends SecurityContext
{
	/** @var  array */
	protected $operationsCache = array();

	/**
	 * @param $objectId
	 * @param $operation
	 * @return bool
	 */
	protected function canDoOperation($objectId, $operation)
	{
		if ($this->userId === static::GUEST_USER)
		{
			return false;
		}

		if (!isset($this->operationsCache[$objectId]))
		{
			$this->operationsCache[$objectId] = $this->getOperationsByObject($objectId);
		}

		return isset($this->operationsCache[$objectId][$operation]);
	}

	protected function getOperationsByObject($objectId)
	{
		$access = new CAccess;
		$access->updateCodes(array('USER_ID' => $this->userId));

		return Driver::getInstance()->getRightsManager()->getUserOperationsByObject($objectId, $this->userId);
	}

	public function preloadOperationsForChildren($parentObjectId)
	{
		$rightsManager = Driver::getInstance()->getRightsManager();

		foreach ($rightsManager->getUserOperationsForChildren($parentObjectId, $this->userId) as $objectId => $operations)
		{
			$this->operationsCache[$objectId] = $operations;
		}
		unset($operations);
	}

	public function preloadOperationsForSpecifiedObjects($parentObjectId, array $objectIds)
	{
		$rightsManager = Driver::getInstance()->getRightsManager();
		foreach ($rightsManager->getUserOperationsForChildren($parentObjectId, $this->userId, $objectIds) as $objectId => $operations)
		{
			$this->operationsCache[$objectId] = $operations;
		}
	}

	/**
	 * @param $targetId
	 * @return bool
	 */
	public function canAdd($targetId)
	{
		return $this->canDoOperation($targetId, RightsManager::OP_ADD);
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canChangeRights($objectId)
	{
		return $this->canDoOperation($objectId, RightsManager::OP_RIGHTS);
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canChangeSettings($objectId)
	{
		return $this->canDoOperation($objectId, RightsManager::OP_SETTINGS);
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canCreateWorkflow($objectId)
	{
		return $this->canDoOperation($objectId, RightsManager::OP_CREATE_WF);
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canDelete($objectId)
	{
		return $this->canDoOperation($objectId, RightsManager::OP_DESTROY);
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canMarkDeleted($objectId)
	{
		return $this->canDoOperation($objectId, RightsManager::OP_DELETE);
	}

	/**
	 * @param $objectId
	 * @param $targetId
	 * @return bool
	 */
	public function canMove($objectId, $targetId)
	{
		return $this->canRead($objectId) && $this->canMarkDeleted($objectId) && $this->canAdd($targetId);
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canRead($objectId)
	{
		return $this->canDoOperation($objectId, RightsManager::OP_READ);
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canRename($objectId)
	{
		return $this->canDoOperation($objectId, RightsManager::OP_EDIT);
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canRestore($objectId)
	{
		return $this->canDoOperation($objectId, RightsManager::OP_RESTORE);
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canShare($objectId)
	{
		return $this->canDoOperation($objectId, RightsManager::OP_SHARING);
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canUpdate($objectId)
	{
		return $this->canDoOperation($objectId, RightsManager::OP_EDIT);
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canStartBizProc($objectId)
	{
		return $this->canDoOperation($objectId, RightsManager::OP_START_BP);
	}

	public function getSqlExpressionForList($columnObjectId, $columnCreatedBy)
	{
		$readableTaskIdsSql = $this->getReadableTaskIdsSql();
		if ($readableTaskIdsSql === null)
		{
			return '1 = 0';
		}

		$tableDiskSimpleRight = SimpleRightTable::getTableName();
		$tableDiskRight = RightTable::getTableName();
		$tableObjectPath = ObjectPathTable::getTableName();

		$accessCodePredicate = $this->buildAccessCodePredicate($columnCreatedBy, 3);

		return  <<<SQL
			EXISTS (
				SELECT 1
				FROM $tableDiskSimpleRight simple_right
				WHERE simple_right.OBJECT_ID = (
					SELECT path.PARENT_ID
					FROM $tableObjectPath path
					WHERE path.OBJECT_ID = $columnObjectId
					  AND EXISTS (
						  SELECT 1
						  FROM $tableDiskRight readable_right
						  WHERE readable_right.OBJECT_ID = path.PARENT_ID
							AND readable_right.TASK_ID IN ($readableTaskIdsSql)
					  )
					ORDER BY path.DEPTH_LEVEL ASC
					LIMIT 1
				)
				  AND (
		$accessCodePredicate
				  )
			)
		SQL;
	}

	public function getSqlExpressionForRootObjectList(string $columnObjectId, string $columnCreatedBy): string
	{
		$readableTaskIdsSql = $this->getReadableTaskIdsSql();
		if ($readableTaskIdsSql === null)
		{
			return '1 = 0';
		}

		$tableDiskSimpleRight = SimpleRightTable::getTableName();
		$tableDiskRight = RightTable::getTableName();

		$accessCodePredicate = $this->buildAccessCodePredicate($columnCreatedBy, 4);

		return <<<SQL
			(
				EXISTS (
					SELECT 1
					FROM $tableDiskRight readable_right
					WHERE readable_right.OBJECT_ID = $columnObjectId
					  AND readable_right.TASK_ID IN ($readableTaskIdsSql)
				)
				AND EXISTS (
					SELECT 1
					FROM $tableDiskSimpleRight simple_right
					WHERE simple_right.OBJECT_ID = $columnObjectId
					  AND (
		$accessCodePredicate
					  )
				)
			)
		SQL;
	}

	/**
	 * Access code rule shared by every rights expression of the module.
	 * $indentLevel aligns the predicate with the SQL block it is embedded into.
	 */
	private function buildAccessCodePredicate(string $columnCreatedBy, int $indentLevel): string
	{
		$userId = (int)$this->userId;
		$tableUserAccess = UserAccessTable::getTableName();

		$accessCodeCreator = AccessCodeEnum::CREATOR->value;
		$accessCodeAuthorizedUser = AccessCodeEnum::AUTHORIZED_USER->value;
		$indent = str_repeat("\t", $indentLevel);

		return <<<SQL
		{$indent}  (simple_right.ACCESS_CODE = '$accessCodeCreator' AND $columnCreatedBy = $userId)

		{$indent}  OR simple_right.ACCESS_CODE = '$accessCodeAuthorizedUser'

		{$indent}  OR simple_right.ACCESS_CODE IN (
		{$indent}	  SELECT ACCESS_CODE
		{$indent}	  FROM $tableUserAccess
		{$indent}	  WHERE USER_ID = $userId
		{$indent}  )
		SQL;
	}

	private function getReadableTaskIdsSql(): ?string
	{
		if ($this->userId === static::GUEST_USER)
		{
			return null;
		}

		$readableTaskIds = Driver::getInstance()->getRightsManager()->getReadableTaskIds();
		if (empty($readableTaskIds))
		{
			return null;
		}

		return implode(', ', array_map('intval', $readableTaskIds));
	}
}