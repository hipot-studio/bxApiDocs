<?php

namespace Bitrix\Disk\Security;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Access\DownloadAccess;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessResult;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessSource;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internals\ObjectPathTable;
use Bitrix\Disk\Internals\RightTable;
use Bitrix\Disk\Internals\SimpleRightTable;
use Bitrix\Disk\RightsManager;
use Bitrix\Disk\Access\AccessCodeEnum;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\UserAccessTable;
use CAccess;

class DiskSecurityContext extends SecurityContext
{
	protected UnifiedLinkAccessService $unifiedLinkAccessService;
	protected DownloadAccess $downloadAccess;
	protected bool $shouldCheckUnifiedLink = false;
	/** @var  array */
	protected $operationsCache = [];
	protected array $unifiedLinkCache = [];
	/** @var array<int, UnifiedLinkAccessResult> */
	protected array $unifiedLinkAccessResultCache = [];
	/** @var array<int, BaseObject|null> */
	protected array $objectCache = [];

	public function __construct($user)
	{
		parent::__construct($user);

		$serviceLocator = ServiceLocator::getInstance();
		$this->unifiedLinkAccessService = $serviceLocator->get(UnifiedLinkAccessService::class);
		$this->downloadAccess = $serviceLocator->get(DownloadAccess::class);
	}

	public function checkUnifiedLink(bool $shouldCheckUnifiedLink): void
	{
		$this->shouldCheckUnifiedLink = $shouldCheckUnifiedLink;
	}

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

		if ($this->shouldCheckUnifiedLink && $this->canDoOperationByUnifiedLink($objectId, $operation))
		{
			return true;
		}

		if (!isset($this->operationsCache[$objectId]))
		{
			$this->operationsCache[$objectId] = $this->getOperationsByObject($objectId);
		}

		return isset($this->operationsCache[$objectId][$operation]);
	}

	protected function canDoOperationByUnifiedLink(mixed $objectId, string $operation): bool
	{
		$accessLevels = Driver::getInstance()->getRightsManager()->getUnifiedLinkAccessLevelsByOperation(
			operation: $operation,
		);

		if (empty($accessLevels))
		{
			return false;
		}

		$objectId = (int)$objectId;

		if (array_key_exists($objectId, $this->unifiedLinkCache))
		{
			$accessLevel = $this->unifiedLinkCache[$objectId];
		}
		else
		{
			$object = $this->getObject($objectId);

			if ($object instanceof BaseObject)
			{
				$accessLevel = $this->getUnifiedLinkAccessLevel($object);
			}
			else
			{
				$this->unifiedLinkCache[$objectId] = null;
				$accessLevel = null;
			}
		}

		if (!$accessLevel instanceof UnifiedLinkAccessLevel)
		{
			return false;
		}

		return in_array($accessLevel->value, $accessLevels, true);
	}

	protected function getOperationsByObject($objectId)
	{
		$access = new CAccess();
		$access->updateCodes(['USER_ID' => $this->userId]);

		return Driver::getInstance()->getRightsManager()->getUserOperationsByObject($objectId, $this->userId);
	}

	public function preloadOperationsForChildren(
		$parentObjectId,
		bool $shouldPreloadDownloadAccess = false,
	): void
	{
		$rightsManager = Driver::getInstance()->getRightsManager();

		$objectIds = [];

		foreach ($rightsManager->getUserOperationsForChildren($parentObjectId, $this->userId) as $objectId => $operations)
		{
			$this->operationsCache[$objectId] = $operations;
			$objectIds[] = (int)$objectId;
		}

		unset($operations);

		$this->preloadAccessForObjectIds($objectIds, $shouldPreloadDownloadAccess);
	}

	public function preloadOperationsForSpecifiedObjects(
		$parentObjectId,
		array $objectIds,
		bool $shouldPreloadDownloadAccess = false,
	): void
	{
		$rightsManager = Driver::getInstance()->getRightsManager();

		$operationsByObjectId = $rightsManager->getUserOperationsForChildren(
			parentId: $parentObjectId,
			userId: $this->userId,
			restrictIds: $objectIds,
		);

		foreach ($operationsByObjectId as $objectId => $operations)
		{
			$this->operationsCache[$objectId] = $operations;
		}

		$this->preloadAccessForObjectIds($objectIds, $shouldPreloadDownloadAccess);
	}

	/**
	 * @param array<int, BaseObject> $objects
	 */
	public function preloadOperationsForObjects(array $objects): void
	{
		$objectIds = [];

		foreach ($objects as $object)
		{
			if (!$object instanceof BaseObject)
			{
				continue;
			}

			$this->cacheObject($object);

			$objectId = (int)$object->getId();
			$realObjectId = (int)$object->getRealObjectId();

			if ($objectId > 0)
			{
				$objectIds[] = $objectId;
			}

			if ($realObjectId > 0 && $realObjectId !== $objectId)
			{
				$objectIds[] = $realObjectId;
			}
		}

		$this->preloadOperationsForObjectIds($objectIds);
	}

	/**
	 * @param array<int, int> $objectIds
	 */
	public function preloadOperationsForObjectIds(array $objectIds): void
	{
		$objectIds = array_values(array_unique(array_filter(array_map('intval', $objectIds))));
		if (empty($objectIds))
		{
			return;
		}

		$operationsByObjectId = $this->getOperationsByObjects($objectIds);

		foreach ($operationsByObjectId as $objectId => $operations)
		{
			$this->operationsCache[$objectId] = $operations;
		}

		foreach ($objectIds as $objectId)
		{
			$this->operationsCache[$objectId] ??= [];
		}

		$this->preloadAccessForObjectIds($objectIds);
	}

	protected function getOperationsByObjects(array $objectIds): array
	{
		return Driver::getInstance()
			->getRightsManager()
			->getUserOperationsForObjects($objectIds, $this->userId)
		;
	}

	protected function preloadAccessForObjectIds(
		array $objectIds,
		bool $shouldPreloadDownloadAccess = true,
	): void
	{
		$objectIds = array_values(array_unique(array_filter(array_map('intval', $objectIds))));

		if (empty($objectIds))
		{
			return;
		}

		$objects = [];
		$objectIdsToLoad = [];
		$objectIdsWithoutObjects = [];

		foreach ($objectIds as $objectId)
		{
			if (array_key_exists($objectId, $this->objectCache))
			{
				$object = $this->objectCache[$objectId];

				if ($object instanceof BaseObject)
				{
					$objects[$objectId] = $object;
				}

				continue;
			}

			if ($this->shouldCheckUnifiedLink)
			{
				$objectIdsToLoad[] = $objectId;
			}
			else
			{
				$objectIdsWithoutObjects[] = $objectId;
			}
		}

		foreach (BaseObject::loadBatchById($objectIdsToLoad) as $object)
		{
			if ($object instanceof BaseObject)
			{
				$objects[(int)$object->getId()] = $object;
				$this->cacheObject($object);
			}
		}

		if ($shouldPreloadDownloadAccess && !empty($objects))
		{
			$this->downloadAccess->preload($objects);
		}

		if (!$this->shouldCheckUnifiedLink)
		{
			if ($shouldPreloadDownloadAccess && !empty($objectIdsWithoutObjects))
			{
				$this->downloadAccess->preloadByObjectIds($objectIdsWithoutObjects);
			}

			return;
		}

		if (empty($objects))
		{
			foreach ($objectIds as $objectId)
			{
				$this->unifiedLinkCache[$objectId] = null;
			}

			return;
		}

		$this->preloadUnifiedLinkAccessLevelsByObjects($objects, $objectIds);
	}

	/**
	 * @param array<int, BaseObject> $objects
	 * @return void
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function preloadUnifiedLinkAccessLevelsForObjects(array $objects): void
	{
		if (!$this->shouldCheckUnifiedLink)
		{
			return;
		}

		$baseObjects = [];

		foreach ($objects as $object)
		{
			if ($object instanceof BaseObject)
			{
				$baseObjects[(int)$object->getId()] = $object;
				$this->cacheObject($object);
			}
		}

		if (empty($baseObjects))
		{
			return;
		}

		$this->preloadUnifiedLinkAccessLevelsByObjects($baseObjects, array_keys($baseObjects));
		$this->downloadAccess->preload($baseObjects);
	}

	/**
	 * @param array<int, BaseObject> $baseObjects
	 * @param array<int, int> $objectIds
	 * @return void
	 */
	protected function preloadUnifiedLinkAccessLevelsByObjects(array $baseObjects, array $objectIds): void
	{
		$accessResultsByObjectId = $this->unifiedLinkAccessService->checkAllCollection(
			baseObjects: $baseObjects,
			userId: (int)$this->userId,
			operationsByObjectId: $this->operationsCache,
		);

		foreach ($objectIds as $objectId)
		{
			$accessResult = $accessResultsByObjectId[$objectId] ?? null;

			if ($accessResult instanceof UnifiedLinkAccessResult)
			{
				$this->unifiedLinkAccessResultCache[$objectId] = $accessResult;
				$this->unifiedLinkCache[$objectId] = $accessResult->getMaxAccessLevel();
			}
			else
			{
				$this->unifiedLinkCache[$objectId] = null;
			}
		}
	}

	protected function getUnifiedLinkAccessLevel(BaseObject $object): ?UnifiedLinkAccessLevel
	{
		$objectId = (int)$object->getId();

		if (!array_key_exists($objectId, $this->unifiedLinkCache))
		{
			$this->unifiedLinkCache[$objectId] = $this->unifiedLinkAccessService->check(
				baseObject: $object,
				userId: (int)$this->userId,
			);
		}

		$accessLevel = $this->unifiedLinkCache[$objectId];

		return $accessLevel instanceof UnifiedLinkAccessLevel ? $accessLevel : null;
	}

	protected function getUnifiedLinkAccessResult(BaseObject $object): UnifiedLinkAccessResult
	{
		$objectId = (int)$object->getId();

		if (!isset($this->unifiedLinkAccessResultCache[$objectId]))
		{
			$accessResult = $this->unifiedLinkAccessService->checkAll(
				baseObject: $object,
				userId: (int)$this->userId,
			);

			$this->unifiedLinkAccessResultCache[$objectId] = $accessResult;
			$this->unifiedLinkCache[$objectId] = $accessResult->getMaxAccessLevel();
		}

		return $this->unifiedLinkAccessResultCache[$objectId];
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
	public function canDownload($objectId)
	{
		$objectId = (int)$objectId;

		if ($this->userId === static::GUEST_USER)
		{
			$object = $this->getObject($objectId);

			if (!$object instanceof BaseObject)
			{
				return false;
			}

			if (!$this->shouldCheckUnifiedLink)
			{
				return false;
			}

			$accessLevel = $this->getUnifiedLinkAccessLevel($object);

			if (
				$accessLevel instanceof UnifiedLinkAccessLevel
				&& $accessLevel->value >= UnifiedLinkAccessLevel::Edit->value
			)
			{
				return true;
			}

			return $accessLevel === UnifiedLinkAccessLevel::Read && $this->downloadAccess->checkExternalLink($object);
		}

		if ($objectId <= 0)
		{
			return false;
		}

		if (!$this->shouldCheckUnifiedLink)
		{
			if (!$this->canRead($objectId))
			{
				return false;
			}

			if ($this->canUpdate($objectId))
			{
				return true;
			}

			return $this->downloadAccess->checkObjectOptionByObjectId($objectId);
		}

		$object = $this->objectCache[$objectId] ?? $this->getObject($objectId);

		if (!$object instanceof BaseObject)
		{
			return false;
		}

		$accessResult = $this->getUnifiedLinkAccessResult($object);
		$maxAccessLevel = $accessResult->getMaxAccessLevel();

		if ($maxAccessLevel === UnifiedLinkAccessLevel::Edit)
		{
			return true;
		}

		if ($maxAccessLevel !== UnifiedLinkAccessLevel::Read)
		{
			return false;
		}

		$privateReadSources = [
			UnifiedLinkAccessSource::ParentUnifiedLink,
			UnifiedLinkAccessSource::UnifiedLink,
			UnifiedLinkAccessSource::PermissionSystem,
			UnifiedLinkAccessSource::AttachedObject,
		];

		foreach ($privateReadSources as $source)
		{
			if (
				$accessResult->getAccessLevel($source) === UnifiedLinkAccessLevel::Read
				&& $this->downloadAccess->checkObjectOption($object)
			)
			{
				return true;
			}
		}

		return $accessResult->getAccessLevel(UnifiedLinkAccessSource::ExternalLink) === UnifiedLinkAccessLevel::Read
			&& $this->downloadAccess->checkExternalLink($object);
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

	protected function getObject(mixed $objectId): ?BaseObject
	{
		$objectId = (int)$objectId;

		if ($objectId <= 0)
		{
			return null;
		}

		if (!array_key_exists($objectId, $this->objectCache))
		{
			$object = BaseObject::getById($objectId);

			if ($object instanceof BaseObject)
			{
				$this->cacheObject($object);
			}
			else
			{
				$this->objectCache[$objectId] = null;
			}
		}

		$object = $this->objectCache[$objectId];

		return $object instanceof BaseObject ? $object : null;
	}

	protected function cacheObject(BaseObject $object): void
	{
		$objectId = (int)$object->getId();

		if ($objectId <= 0)
		{
			return;
		}

		$this->objectCache[$objectId] = $object;
	}
}
