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
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Internals\RightTable;
use Bitrix\Disk\Internals\SimpleRightTable;
use Bitrix\Disk\RightsManager;
use Bitrix\Disk\Access\AccessCodeEnum;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserAccessTable;
use CAccess;

class DiskSecurityContext extends SecurityContext
{
	private const FAST_PATH_OPTION = 'subtree_rights_fast_path_enabled';
	private const FAST_PATH_MIN_CANDIDATES_OPTION = 'subtree_rights_fast_path_min_candidates';

	/** The subtree is governed by rules of its own inside, there is nothing single to fold. */
	private const SUBTREE_VERDICT_FALLBACK = 'fallback';
	private const SUBTREE_VERDICT_ALLOW_ALL = 'allowAll';
	private const SUBTREE_VERDICT_OWN_ONLY = 'ownOnly';
	private const SUBTREE_VERDICT_DENY = 'deny';

	/**
	 * The two forms cross at about six hundred candidate rows, measured on the snapshot - three pages of it.
	 * A default and not the cutoff itself: the crossing is a property of the data of a portal, so the option
	 * carries it and a portal whose data puts it elsewhere is retuned without a release.
	 */
	private const SUBTREE_FAST_PATH_MIN_CANDIDATES_DEFAULT = 600;

	/**
	 * Bumped on every write of rights, so that verdicts resolved before it are dropped.
	 * @see self::invalidateSubtreeVerdicts()
	 */
	private static int $subtreeVerdictGeneration = 0;

	/** @var array<int, string> verdict by the root of the subtree; the instance is bound to one user */
	private array $subtreeVerdictMemo = [];
	/** @var array<string, bool> decision of the cutoff by the root of the subtree and the bound of the caller */
	private array $subtreeCutoffMemo = [];
	private int $subtreeVerdictMemoGeneration = 0;

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

	public function getSqlExpressionForSubtreeList(
		string $columnObjectId,
		string $columnCreatedBy,
		int $rootObjectId,
		?DateTime $candidatesChangedSince = null,
	): string
	{
		return $this->specializeForSubtree($rootObjectId, $columnCreatedBy, $candidatesChangedSince)
			?? $this->getSqlExpressionForList($columnObjectId, $columnCreatedBy)
		;
	}

	/**
	 * Drops the verdicts every context of the request has folded so far.
	 *
	 * A folded verdict stands on b_disk_right, the rows the checkpoints are read off, and on
	 * b_disk_object_path, the closure the subtree is enumerated by. Moving a folder carrying a checkpoint of
	 * its own into a subtree already folded changes no rights row at all, and a verdict kept over that move
	 * would answer for rows it was never resolved on.
	 *
	 * Called from the writes of rights through RightsManager (insertRightsInternal, deleteInternal,
	 * deleteBaseDomainRights) and from the three rewrites of the closure (ObjectPathTable::moveTo, recalculate, recalculateByStorage) - that is
	 * the coverage of today, not an invariant held by every writer. Destroying an object (File, Folder,
	 * FileLink) and dropping the codes of a group (SocialnetworkHandlers) write past it, and both only narrow
	 * what is visible. Rebuilding the snapshot (Rights\SetupSession::finish()) writes b_disk_simple_right past
	 * it after the write that started the rebuild has already dropped the verdicts, so a verdict folded in
	 * between reads the snapshot mid-rebuild; closing that needs a drop at the level of the tables.
	 *
	 * The contexts are scattered over the request and a write knows none of them, hence a generation counter.
	 */
	public static function invalidateSubtreeVerdicts(): void
	{
		self::$subtreeVerdictGeneration++;
	}

	/**
	 * Folds the rights of a subtree governed by a single checkpoint into a predicate of no subquery at all.
	 * Returns null when the subtree does not qualify and the universal expression has to be used instead,
	 * and null as well when the caller declared its candidates and the cutoff found them too few for the
	 * fold to pay for itself.
	 *
	 * @see \Bitrix\Disk\Security\SecurityContext::getSqlExpressionForSubtreeList() for when this is allowed
	 */
	private function specializeForSubtree(
		int $rootObjectId,
		string $columnCreatedBy,
		?DateTime $candidatesChangedSince,
	): ?string
	{
		if (!$this->isSubtreeFastPathEnabled() || $rootObjectId <= 0)
		{
			return null;
		}

		// Step 0. The denial by user comes from the source the universal expression takes it from, and not
		// from a copy of the condition: a guest and an empty list of reading tasks must be denied alike.
		// Both operations the steps below stand on take an object id and know nothing of the user at all,
		// so without this step a guest would be given away the whole subtree by an AU code on its root.
		if ($this->getReadableTaskIdsSql() === null)
		{
			return '1 = 0';
		}

		$verdict = $this->resolveSubtreeVerdict($rootObjectId, $candidatesChangedSince);

		// Step 4. The string is built after the memo, out of the columns of the current caller.
		return match ($verdict)
		{
			self::SUBTREE_VERDICT_ALLOW_ALL => '1 = 1',
			self::SUBTREE_VERDICT_OWN_ONLY => $columnCreatedBy . ' = ' . (int)$this->userId,
			self::SUBTREE_VERDICT_DENY => '1 = 0',
			default => null,
		};
	}

	/** Null when the subtree was not folded at all, because the cutoff stepped aside from it. */
	private function resolveSubtreeVerdict(int $rootObjectId, ?DateTime $candidatesChangedSince): ?string
	{
		if ($this->subtreeVerdictMemoGeneration !== self::$subtreeVerdictGeneration)
		{
			$this->subtreeVerdictMemo = [];
			$this->subtreeCutoffMemo = [];
			$this->subtreeVerdictMemoGeneration = self::$subtreeVerdictGeneration;
		}

		if (isset($this->subtreeVerdictMemo[$rootObjectId]))
		{
			return $this->subtreeVerdictMemo[$rootObjectId];
		}

		if (
			$candidatesChangedSince !== null
			&& !$this->isSubtreeWorthFoldingOnce($rootObjectId, $candidatesChangedSince)
		)
		{
			return null;
		}

		return $this->subtreeVerdictMemo[$rootObjectId] = $this->foldSubtreeRights($rootObjectId);
	}

	/** Memoized by the root and the bound together: two callers on the same root may name different bounds. */
	private function isSubtreeWorthFoldingOnce(int $rootObjectId, DateTime $candidatesChangedSince): bool
	{
		$memoKey = $rootObjectId . ':' . $candidatesChangedSince->getTimestamp();

		return $this->subtreeCutoffMemo[$memoKey] ??= $this->isSubtreeWorthFolding(
			$rootObjectId,
			$candidatesChangedSince,
		);
	}

	/**
	 * The storage of the root is named so that the probe is entered by the changes and not by the closure.
	 * The offset stops after that many rows matched and not after that many read, so on an idle poll, where
	 * nothing matches, a probe entered by the closure reads the subtree whole to answer no. Named, the
	 * storage takes the query into IX_DISK_O_13 (STORAGE_ID, SYNC_UPDATE_TIME), and the closure is only
	 * visited for the rows the changes already gave: the probe then costs the very quantity it measures.
	 */
	private function isSubtreeWorthFolding(int $rootObjectId, DateTime $candidatesChangedSince): bool
	{
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$tableObjectPath = $sqlHelper->quote(ObjectPathTable::getTableName());
		$tableObject = $sqlHelper->quote(ObjectTable::getTableName());
		$deletedTypeNone = ObjectTable::DELETED_TYPE_NONE;
		$changedSince = $sqlHelper->convertToDbDateTime($candidatesChangedSince);
		$offset = $this->getSubtreeFastPathMinCandidates() - 1;

		$sql = <<<SQL
			SELECT 1
			FROM {$tableObjectPath} path
				INNER JOIN {$tableObject} object_table ON object_table.ID = path.OBJECT_ID
			WHERE path.PARENT_ID = {$rootObjectId}
				AND object_table.STORAGE_ID = (
					SELECT root_object.STORAGE_ID
					FROM {$tableObject} root_object
					WHERE root_object.ID = {$rootObjectId}
				)
				AND object_table.DELETED_TYPE = {$deletedTypeNone}
				AND object_table.SYNC_UPDATE_TIME >= {$changedSince}
			LIMIT 1 OFFSET {$offset}
		SQL;

		return $connection->query($sql)->fetch() !== false;
	}

	/** The verdict is folded and memoized, the predicate string is not: the columns belong to the caller. */
	private function foldSubtreeRights(int $rootObjectId): string
	{
		$rightsManager = Driver::getInstance()->getRightsManager();

		// Step 1. A checkpoint standing strictly inside governs a part of the subtree by rules of its own,
		// and the root itself is excluded by the query: rights on the root are the very case of the fast
		// path. A denying checkpoint is a checkpoint too, and it is detected here alike.
		if ($rightsManager->hasCheckpointDescendant($rootObjectId))
		{
			return self::SUBTREE_VERDICT_FALLBACK;
		}

		// Step 2. With none inside, the nearest checkpoint of every object of the subtree is the nearest
		// checkpoint of its root, and the snapshot of that one governs the subtree whole.
		$accessCodes = $rightsManager->getSimpleRightAccessCodesForObject($rootObjectId);
		if (empty($accessCodes))
		{
			return self::SUBTREE_VERDICT_DENY;
		}

		// Step 3. The order of the branches is load-bearing: every earlier one is strictly stronger than
		// the creator branch, which is why that one goes strictly last. Lifted above the codes of the user
		// it would turn the disjunction of the universal predicate into an exclusive choice.
		if (in_array(AccessCodeEnum::AUTHORIZED_USER->value, $accessCodes, true))
		{
			return self::SUBTREE_VERDICT_ALLOW_ALL;
		}

		if ($this->hasAnyAccessCode($accessCodes))
		{
			return self::SUBTREE_VERDICT_ALLOW_ALL;
		}

		if (in_array(AccessCodeEnum::CREATOR->value, $accessCodes, true))
		{
			return self::SUBTREE_VERDICT_OWN_ONLY;
		}

		return self::SUBTREE_VERDICT_DENY;
	}

	/**
	 * One indexed query over the codes of the checkpoint, of which there are units, and not a readout of
	 * the codes of the user, of which there are hundreds. A SELECT and nothing else: the universal
	 * expression reads b_user_access as it stands and never calls CAccess::updateCodes(), and a fast path
	 * judging by fresher codes than the expression it stands in for would diverge from it.
	 *
	 * @param string[] $accessCodes
	 */
	private function hasAnyAccessCode(array $accessCodes): bool
	{
		$row = UserAccessTable::getList([
			'select' => ['USER_ID'],
			'filter' => [
				'=USER_ID' => (int)$this->userId,
				'@ACCESS_CODE' => $accessCodes,
			],
			'limit' => 1,
		])->fetch();

		return $row !== false;
	}

	private function isSubtreeFastPathEnabled(): bool
	{
		// New behaviour on the hot path of rights, hence off by default.
		return Option::get('disk', self::FAST_PATH_OPTION, 'N') === 'Y';
	}

	private function getSubtreeFastPathMinCandidates(): int
	{
		$minCandidates = (int)Option::get(
			'disk',
			self::FAST_PATH_MIN_CANDIDATES_OPTION,
			(string)self::SUBTREE_FAST_PATH_MIN_CANDIDATES_DEFAULT,
		);

		return max(1, $minCandidates);
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
