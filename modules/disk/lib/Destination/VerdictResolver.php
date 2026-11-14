<?php

declare(strict_types=1);

namespace Bitrix\Disk\Destination;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internal\Enum\WriteOperationContext;
use Bitrix\Disk\Internals\FileTable;
use Bitrix\Disk\Internals\ObjectNameKey;
use Bitrix\Disk\Internals\ObjectNameService;
use Bitrix\Disk\Internals\ObjectPathTable;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Disk\Storage;
use Bitrix\Main\ObjectException;
use Bitrix\Main\ORM\Fields\ExpressionField;

/**
 * The only place where the suitability rules of a destination live.
 *
 * The destination is resolved to the folder it points at before anything is weighed
 * (@see resolveRealFolder): a link and the folder behind it can live in different storages, and the
 * operation lands in the latter.
 *
 * The order of the checks is a part of the contract: the tariff is checked right after the folder exists and
 * before the rights, because a common storage closed by the tariff stays writable on the rights level.
 *
 * Not everything the resolver knows becomes a verdict: a refusal that belongs to the object rather than to the
 * chosen folder is asked for apart (@see isOutOfReachAsLink) and answered by the action, not by the verdict.
 */
class VerdictResolver
{
	private const LEVEL_BATCH_SIZE = 300;

	private readonly StorageTariffRestriction $tariffRestriction;

	private readonly ObjectSizeCache $objectSizeCache;

	/** @var int[]|null ancestors shared by every folder of the level */
	private ?array $levelAncestorIds = null;

	/** @var array<int, int[]>|null ancestors of a folder, itself left out, by the id of that folder */
	private ?array $ancestorIdsByFolderId = null;

	/** @var array<int, true>|null real ids of the folders weighed at once that already hold the name of the source */
	private ?array $levelBusyParentIds = null;

	/** @var array<int, true>|null real ids of the folders of the level holding a name of the selection */
	private ?array $levelSelectionBusyParentIds = null;

	/** @var array<int, BaseObject|null> real objects behind the links of the level, by their own id */
	private array $levelRealObjects = [];

	/** @var array<int, int[]> ancestors of the real folders behind the links of the level, by real id */
	private array $levelLinkAncestorIds = [];

	/** @var array<string, bool> free space answers already given, by storage and size of the object */
	private array $spaceAllowance = [];

	/** @var array<int, int> sizes already weighed, by the id of the object they belong to */
	private array $objectSizes = [];

	/** @var array<string, bool> free space answers already given to a selection, by storage and summed size */
	private array $selectionSpaceAllowance = [];

	/** @var array<int, bool> whether the source may be removed, by the id of the object */
	private array $removableSources = [];

	/**
	 * The size of the source is counted anew unless the caller asks for otherwise, because free space is
	 * weighed here and nowhere below: a verdict is the permission the operation writes by, and a caller who
	 * forgot to choose must not be the one who lets a stale answer decide it. The reader of a level asks for
	 * @see ObjectSizeCache::keepingAnswers(), and forgetting that costs a query, not a permission.
	 */
	public function __construct(
		private readonly int $userId,
		?StorageTariffRestriction $tariffRestriction = null,
		?ObjectSizeCache $objectSizeCache = null,
	)
	{
		$this->tariffRestriction = $tariffRestriction ?? new StorageTariffRestriction();
		$this->objectSizeCache = $objectSizeCache ?? ObjectSizeCache::countingEveryTime();
	}

	/**
	 * Returns a resolver that already knows the facts every folder of the level would ask the database for on
	 * its own: the ancestors of the level, the folders where the name of the source is taken, and the real
	 * objects behind the links of the level together with their own ancestors and their storages.
	 *
	 * Ancestors of a child are the ancestors of its parent plus the parent itself, so one query answers the
	 * whole level; the busy names take one more. A level holding no link asks nothing beyond those two - a
	 * folder that is not a link is its own real object and its chain is the chain of the level. The verdicts
	 * stay exactly the ones the per-folder path gives. An empty level asks nothing, so it gets no prefetch
	 * at all.
	 *
	 * @param Folder[] $targetFolders
	 */
	public function forLevel(BaseObject $object, Folder $parentFolder, array $targetFolders): static
	{
		$levelResolver = clone $this;
		if (empty($targetFolders))
		{
			return $levelResolver;
		}

		$levelResolver->levelAncestorIds = $object instanceof Folder
			? $this->loadLevelAncestorIds($parentFolder)
			: [];
		$levelResolver->levelBusyParentIds = $this->loadBusyParentIds($object, $targetFolders);
		$levelResolver->levelRealObjects = $this->loadLevelRealObjects($targetFolders);
		$this->preloadLevelStorages($levelResolver->levelRealObjects);

		//only a folder is ever weighed against the chain of a destination (@see isInsideSourceSubtree)
		$levelResolver->levelLinkAncestorIds = $object instanceof Folder
			? $this->loadLevelLinkAncestorIds($levelResolver->levelRealObjects)
			: [];

		return $levelResolver;
	}

	/**
	 * The prefetch of a level for a whole selection: the very facts forLevel() reads for one object, read
	 * once for all the objects of the selection. The ancestors of the level are asked when the selection holds
	 * a folder, and the names of the selection taken in the folders of the level are asked for every name at
	 * once (@see loadSelectionBusyParentIds). The verdicts stay exactly the ones the per-folder path gives.
	 *
	 * What belongs to an object rather than to a folder - the right to remove it, its size - is asked once
	 * for the level all the same: the resolver keeps it by the id of the object.
	 *
	 * @param Folder[] $targetFolders
	 */
	public function forSelectionLevel(ObjectSelection $selection, Folder $parentFolder, array $targetFolders): static
	{
		$levelResolver = clone $this;
		if (empty($targetFolders) || count($selection) === 0)
		{
			return $levelResolver;
		}

		$hasFolder = $this->holdsFolder($selection);
		$levelResolver->levelAncestorIds = $hasFolder ? $this->loadLevelAncestorIds($parentFolder) : [];
		$levelResolver->levelSelectionBusyParentIds = $this->loadSelectionBusyParentIds($selection, $targetFolders);
		$levelResolver->levelRealObjects = $this->loadLevelRealObjects($targetFolders);
		$this->preloadLevelStorages($levelResolver->levelRealObjects);

		//only a folder is ever weighed against the chain of a destination (@see isInsideSourceSubtree)
		$levelResolver->levelLinkAncestorIds = $hasFolder
			? $this->loadLevelLinkAncestorIds($levelResolver->levelRealObjects)
			: [];

		return $levelResolver;
	}

	/**
	 * The same prefetch for a ready answer, whose folders share no parent and are no level at all.
	 *
	 * The ancestors cannot be taken from a parent here, so they are given folder by folder: the caller has
	 * read them already, the paths of the answer being built of the very same rows, and the prefetch costs no
	 * query of its own. The taken names never belonged to the parent - they are asked for by the ids of the
	 * target folders themselves - so the whole answer needs one query there instead of one per parent.
	 *
	 * The rows standing for folders kept elsewhere are read the very way a level reads them: a link of an
	 * answer is weighed against the folder it points at, and neither that folder, nor its own chain of
	 * ancestors, nor the storage it lives in belongs to the rows the caller has read. An answer holding no
	 * link asks nothing for all three.
	 *
	 * @param Folder[] $targetFolders
	 * @param array<int, int[]> $ancestorIdsByFolderId ancestors of a folder, itself left out, by folder id
	 */
	public function forFoundFolders(
		BaseObject $object,
		array $targetFolders,
		array $ancestorIdsByFolderId,
	): static
	{
		$resolver = clone $this;
		if (empty($targetFolders))
		{
			return $resolver;
		}

		$resolver->ancestorIdsByFolderId = $ancestorIdsByFolderId;
		$resolver->levelBusyParentIds = $this->loadBusyParentIds($object, $targetFolders);
		$resolver->levelRealObjects = $this->loadLevelRealObjects($targetFolders);
		$this->preloadLevelStorages($resolver->levelRealObjects);

		//only a folder is ever weighed against the chain of a destination (@see isInsideSourceSubtree)
		$resolver->levelLinkAncestorIds = $object instanceof Folder
			? $this->loadLevelLinkAncestorIds($resolver->levelRealObjects)
			: [];

		return $resolver;
	}

	public function resolveVerdict(
		BaseObject $object,
		Folder $targetFolder,
		DestinationOperation $operation,
	): DestinationVerdict
	{
		$realTargetFolder = $this->resolveRealFolder($targetFolder);
		$targetStorage = $realTargetFolder?->getStorage();
		if (
			$targetFolder->isDeleted()
			|| $realTargetFolder === null
			|| $realTargetFolder->isDeleted()
			|| !$targetStorage instanceof Storage
		)
		{
			return DestinationVerdict::deny(DestinationVerdict::REASON_FOLDER_GONE);
		}

		$targetContext = $this->getSecurityContext($targetStorage);
		if (!$realTargetFolder->canRead($targetContext))
		{
			return DestinationVerdict::deny(DestinationVerdict::REASON_FOLDER_GONE);
		}

		if ($this->tariffRestriction->isRestricted($targetStorage))
		{
			return DestinationVerdict::deny(DestinationVerdict::REASON_STORAGE_RESTRICTED);
		}

		$targetRealId = (int)$realTargetFolder->getId();
		if (
			$targetRealId === (int)$object->getRealObjectId()
			|| $this->isInsideSourceSubtree($object, $targetFolder, $realTargetFolder)
		)
		{
			return DestinationVerdict::deny(DestinationVerdict::REASON_SOURCE_SUBTREE);
		}

		if (
			$operation === DestinationOperation::Move
			&& $targetRealId === (int)$object->getParentId()
		)
		{
			return DestinationVerdict::deny(DestinationVerdict::REASON_ALREADY_HERE);
		}

		if (!$realTargetFolder->canAdd($targetContext))
		{
			return DestinationVerdict::deny(DestinationVerdict::REASON_NO_RIGHTS);
		}

		if ($operation === DestinationOperation::Move && !$this->canRemoveSource($object))
		{
			return DestinationVerdict::deny(DestinationVerdict::REASON_NO_RIGHTS);
		}

		$needsSpace = $this->needsSpace(
			$operation,
			(int)$object->getStorageId(),
			(int)$realTargetFolder->getStorageId(),
		);
		if ($needsSpace && !$this->hasEnoughSpace($object, $targetStorage))
		{
			return DestinationVerdict::deny(DestinationVerdict::REASON_NO_SPACE);
		}

		return DestinationVerdict::allow($this->resolveNameConflict($object, $realTargetFolder));
	}

	/**
	 * The verdict for a whole selection, all or nothing: the rules of the folder are weighed once, the rules
	 * of an object for every object of the selection. The order of the checks is the order of the single
	 * verdict, and every check runs over the whole selection before the next one starts, so the reason is the
	 * first one by the contract whichever object it belongs to, and among the objects - the first one chosen.
	 *
	 * A refusal of an object names it (@see DestinationVerdict::$blockingObjectId); a refusal of the folder
	 * names nothing. A link is refused here rather than by the error of the action the single path uses
	 * (@see isOutOfReachAsLink): one object of a selection must not leave the client without the verdict for
	 * every folder of the level.
	 *
	 * Free space is weighed once for the sum of the objects that need it, the subtrees of their folders by one
	 * aggregate (@see loadFolderSizes). The subtrees of a normalized selection never intersect, so nothing is
	 * counted twice. The rule is stricter than the single one: the
	 * personal quota is weighed too (@see selectionSpaceAllows).
	 */
	public function resolveSelectionVerdict(
		ObjectSelection $selection,
		Folder $targetFolder,
		DestinationOperation $operation,
	): DestinationVerdict
	{
		$realTargetFolder = $this->resolveRealFolder($targetFolder);
		$targetStorage = $realTargetFolder?->getStorage();
		if (
			$targetFolder->isDeleted()
			|| $realTargetFolder === null
			|| $realTargetFolder->isDeleted()
			|| !$targetStorage instanceof Storage
		)
		{
			return DestinationVerdict::deny(DestinationVerdict::REASON_FOLDER_GONE);
		}

		$targetContext = $this->getSecurityContext($targetStorage);
		if (!$realTargetFolder->canRead($targetContext))
		{
			return DestinationVerdict::deny(DestinationVerdict::REASON_FOLDER_GONE);
		}

		if ($this->tariffRestriction->isRestricted($targetStorage))
		{
			return DestinationVerdict::deny(DestinationVerdict::REASON_STORAGE_RESTRICTED);
		}

		$targetRealId = (int)$realTargetFolder->getId();
		$targetAncestorIds = null;
		foreach ($selection as $object)
		{
			if ($targetRealId === (int)$object->getRealObjectId())
			{
				return DestinationVerdict::denyFor(DestinationVerdict::REASON_SOURCE_SUBTREE, (int)$object->getId());
			}

			if (!$object instanceof Folder)
			{
				continue;
			}

			//the chain of the folder is one for every object, so it is asked once (@see isInsideSourceSubtree)
			$targetAncestorIds ??= $this->resolveAncestorIds($targetFolder, $realTargetFolder);
			if (in_array((int)$object->getRealObjectId(), $targetAncestorIds, true))
			{
				return DestinationVerdict::denyFor(DestinationVerdict::REASON_SOURCE_SUBTREE, (int)$object->getId());
			}
		}

		if ($operation === DestinationOperation::Move)
		{
			foreach ($selection as $object)
			{
				if ($targetRealId === (int)$object->getParentId())
				{
					return DestinationVerdict::denyFor(DestinationVerdict::REASON_ALREADY_HERE, (int)$object->getId());
				}
			}
		}

		foreach ($selection as $object)
		{
			if ($this->isOutOfReachAsLink($object, $targetFolder, $operation))
			{
				return DestinationVerdict::denyFor(DestinationVerdict::REASON_LINK_UNSUPPORTED, (int)$object->getId());
			}
		}

		if (!$realTargetFolder->canAdd($targetContext))
		{
			return DestinationVerdict::deny(DestinationVerdict::REASON_NO_RIGHTS);
		}

		if ($operation === DestinationOperation::Move)
		{
			foreach ($selection as $object)
			{
				if (!$this->canRemoveSelectedSource($object))
				{
					return DestinationVerdict::denyFor(DestinationVerdict::REASON_NO_RIGHTS, (int)$object->getId());
				}
			}
		}

		$objectsToWrite = [];
		foreach ($selection as $object)
		{
			if ($this->needsSpace($operation, (int)$object->getStorageId(), (int)$realTargetFolder->getStorageId()))
			{
				$objectsToWrite[] = $object;
			}
		}

		$sizeToWrite = $this->resolveSelectionSize($objectsToWrite);

		if ($sizeToWrite > 0 && !$this->hasEnoughSpaceForSelection($targetStorage, $sizeToWrite))
		{
			return DestinationVerdict::deny(DestinationVerdict::REASON_NO_SPACE);
		}

		return DestinationVerdict::allow($this->resolveSelectionNameConflict($selection, $realTargetFolder));
	}

	protected function needsSpace(
		DestinationOperation $operation,
		int $sourceStorageId,
		int $targetStorageId,
	): bool
	{
		return $operation === DestinationOperation::Copy || $sourceStorageId !== $targetStorageId;
	}

	/**
	 * A link is a name pointing at a body kept elsewhere, and the object model refuses every operation that
	 * would have to carry that body along: FileLink and FolderLink answer copyTo() with a refusal of their
	 * own, and a move between storages goes through the very same copyTo(). Only a move inside one storage
	 * has nothing to carry - it relocates the row of the link - so that one is left allowed.
	 *
	 * Copying a link the way the legacy handler did (copy the real object, rename the copy into the name of
	 * the link) is not implemented in this surface, so the answer of this method is a refusal, not a promise
	 * of a success the execution cannot deliver.
	 *
	 * Deliberately kept out of the verdict. A verdict speaks about the chosen folder and its reasons are a
	 * closed list (@see DestinationVerdict); this refusal speaks about the object, holds for every folder of
	 * the level alike, and reaches the client as the error of the action - the transport code `object_gone`
	 * the client already reads.
	 */
	public function isOutOfReachAsLink(
		BaseObject $object,
		Folder $targetFolder,
		DestinationOperation $operation,
	): bool
	{
		if (!$object->isLink())
		{
			return false;
		}

		if ($this->loadRealObject($object) === null || $operation === DestinationOperation::Copy)
		{
			return true;
		}

		//moveTo() weighs the storage of the source against the storage of the real target folder
		return (int)$object->getStorageId() !== (int)$this->resolveRealObject($targetFolder)?->getStorageId();
	}

	/**
	 * A link whose realObjectId points at nothing, or at an object of the wrong kind, has no real object
	 * at all: both outcomes leave the caller with null.
	 */
	protected function loadRealObject(BaseObject $object): ?BaseObject
	{
		try
		{
			return $object->getRealObject();
		}
		catch (ObjectException)
		{
			return null;
		}
	}

	protected function isInsideSourceSubtree(
		BaseObject $object,
		Folder $targetFolder,
		Folder $realTargetFolder,
	): bool
	{
		if (!$object instanceof Folder)
		{
			return false;
		}

		return in_array(
			(int)$object->getRealObjectId(),
			$this->resolveAncestorIds($targetFolder, $realTargetFolder),
			true,
		);
	}

	/**
	 * @return int[]
	 */
	protected function loadAncestorIds(int $folderId): array
	{
		return array_map(
			static fn(array $path): int => (int)$path['PARENT_ID'],
			ObjectPathTable::getAncestors($folderId),
		);
	}

	protected function hasNameConflict(BaseObject $object, Folder $targetFolder): bool
	{
		$nameService = new ObjectNameService(
			$object->getName(),
			(int)$targetFolder->getRealObjectId(),
			(int)$object->getType(),
		);

		return !$nameService->isUniqueName()->isSuccess();
	}

	/**
	 * A level opened on a link lives under the folder the link points at: getChildren() of a link answers
	 * with the children of the real folder. Ancestors of the id of the link itself would hold neither that
	 * folder nor anything above it. For a folder that is not a link both ids are the same.
	 *
	 * @return int[]
	 */
	protected function loadLevelAncestorIds(Folder $parentFolder): array
	{
		$realParentId = (int)$parentFolder->getRealObjectId();

		return [...$this->loadAncestorIds($realParentId), $realParentId];
	}

	/**
	 * The batch twin of ObjectNameService::isUniqueName over every folder given at once.
	 *
	 * @param Folder[] $targetFolders
	 * @return array<int, true>
	 */
	protected function loadBusyParentIds(BaseObject $object, array $targetFolders): array
	{
		$parentIds = [];
		foreach ($targetFolders as $targetFolder)
		{
			$parentId = (int)$targetFolder->getRealObjectId();
			if ($parentId > 0)
			{
				$parentIds[$parentId] = $parentId;
			}
		}

		if (empty($parentIds))
		{
			return [];
		}

		$busyParentIds = [];
		foreach (array_chunk(array_values($parentIds), self::LEVEL_BATCH_SIZE) as $parentIdBatch)
		{
			$rows = ObjectNameKey::findAllSameName(
				[
					'select' => ['PARENT_ID', 'NAME'],
					'filter' => ['@PARENT_ID' => $parentIdBatch],
				],
				$object->getName(),
			);
			foreach ($rows as $row)
			{
				$busyParentIds[(int)$row['PARENT_ID']] = true;
			}
		}

		return $busyParentIds;
	}

	/**
	 * The batch twin of isAnyNameTaken() over every folder given at once: one query over the keys of the names
	 * and one over the rows stored before the key appeared, whatever the number of the names and the folders.
	 *
	 * @param Folder[] $targetFolders
	 * @return array<int, true>
	 */
	protected function loadSelectionBusyParentIds(ObjectSelection $selection, array $targetFolders): array
	{
		$parentIds = [];
		foreach ($targetFolders as $targetFolder)
		{
			$parentId = (int)$targetFolder->getRealObjectId();
			if ($parentId > 0)
			{
				$parentIds[$parentId] = $parentId;
			}
		}

		$namesByKey = [];
		foreach ($selection as $object)
		{
			$name = (string)$object->getName();
			$namesByKey[ObjectNameKey::of($name)] = $name;
		}

		if (empty($parentIds) || empty($namesByKey))
		{
			return [];
		}

		$busyParentIds = [];
		foreach (array_chunk(array_values($parentIds), self::LEVEL_BATCH_SIZE) as $parentIdBatch)
		{
			$parameters = [
				'select' => ['PARENT_ID', 'NAME'],
				'filter' => ['@PARENT_ID' => $parentIdBatch],
			];

			$rowsWithKey = ObjectTable::getList([
				...$parameters,
				'filter' => [...$parameters['filter'], '@NAME_KEY' => array_keys($namesByKey)],
			]);
			while ($row = $rowsWithKey->fetch())
			{
				$busyParentIds[(int)$row['PARENT_ID']] = true;
			}

			$rowsWithoutKey = ObjectTable::getList(
				ObjectNameKey::buildParametersWithoutKey($parameters, array_values($namesByKey)),
			);
			while ($row = $rowsWithoutKey->fetch())
			{
				if (isset($namesByKey[ObjectNameKey::of((string)$row['NAME'])]))
				{
					$busyParentIds[(int)$row['PARENT_ID']] = true;
				}
			}
		}

		return $busyParentIds;
	}

	/**
	 * The real objects behind the links of the level, read in one query.
	 *
	 * A link is the one row of a level whose body lives elsewhere, and reaching that body is a load of its
	 * own (@see BaseObject::getRealObject) with no cache behind it. A level holding no link asks nothing here.
	 *
	 * An id the batch found nothing for is kept in the answer as a null, so that a link pointing at nothing
	 * is told from a link the batch was never asked about and costs no load of its own.
	 *
	 * @param Folder[] $targetFolders
	 * @return array<int, BaseObject|null>
	 */
	protected function loadLevelRealObjects(array $targetFolders): array
	{
		$realObjectIds = [];
		foreach ($targetFolders as $targetFolder)
		{
			if (!$targetFolder->isLink())
			{
				continue;
			}

			$realObjectId = (int)$targetFolder->getRealObjectId();
			if ($realObjectId > 0)
			{
				$realObjectIds[$realObjectId] = $realObjectId;
			}
		}

		$realObjects = array_fill_keys($realObjectIds, null);
		foreach (array_chunk(array_values($realObjectIds), self::LEVEL_BATCH_SIZE) as $realObjectIdBatch)
		{
			foreach (BaseObject::loadBatchById($realObjectIdBatch) as $realObject)
			{
				$realObjects[(int)$realObject->getId()] = $realObject;
			}
		}

		return $realObjects;
	}

	/**
	 * The ancestors of the real folders behind the links of the level, read in one query.
	 *
	 * Every other folder of the level shares the ancestors of the level (@see loadLevelAncestorIds); a link
	 * stands for a folder kept elsewhere, and the chain of that folder is its own. The batch twin of
	 * loadAncestorIds() over all of them at once, without the order of the chain: the only question ever
	 * asked of it is whether the source is in it (@see isInsideSourceSubtree).
	 *
	 * A folder is kept in the answer even when it has no ancestors, so that an empty chain is told from a
	 * folder the batch was never asked about.
	 *
	 * @param array<int, BaseObject> $realObjects
	 * @return array<int, int[]>
	 */
	protected function loadLevelLinkAncestorIds(array $realObjects): array
	{
		$folderIds = [];
		foreach ($realObjects as $realObject)
		{
			if ($realObject instanceof Folder)
			{
				$folderIds[] = (int)$realObject->getId();
			}
		}

		if (empty($folderIds))
		{
			return [];
		}

		$ancestorIds = array_fill_keys($folderIds, []);
		foreach (array_chunk($folderIds, self::LEVEL_BATCH_SIZE) as $folderIdBatch)
		{
			$rows = ObjectPathTable::query()
				->setSelect(['OBJECT_ID', 'PARENT_ID'])
				->whereIn('OBJECT_ID', $folderIdBatch)
				->exec()
			;
			while ($row = $rows->fetch())
			{
				$objectId = (int)$row['OBJECT_ID'];
				$parentId = (int)$row['PARENT_ID'];
				if ($parentId !== $objectId)
				{
					$ancestorIds[$objectId][] = $parentId;
				}
			}
		}

		return $ancestorIds;
	}

	/**
	 * Reads the storages the links of the level point into, all of them in one query.
	 *
	 * Sharing leaves a link in the storage of the person it was shared with and keeps the folder itself in the
	 * storage of the one who shared it, so a row of such a level reaches for a storage of its own
	 * (@see BaseObject::getStorage) and used to read it alone. The answer is left to the process cache the
	 * loader keeps rather than taken here: every row asks for its storage by itself, and now finds it.
	 *
	 * The rights behind those storages stay a read of every row, because a security context belongs to the
	 * storage it was asked of and nothing public puts a ready one there.
	 *
	 * That process cache is the only place this writes: no state of the resolver is touched, so the calls in
	 * forLevel() and forFoundFolders() keep no result on purpose.
	 *
	 * @param array<int, BaseObject|null> $realObjects
	 */
	protected function preloadLevelStorages(array $realObjects): void
	{
		$storageIds = [];
		foreach ($realObjects as $realObject)
		{
			if (!$realObject instanceof Folder)
			{
				continue;
			}

			$storageId = (int)$realObject->getStorageId();
			if ($storageId > 0)
			{
				$storageIds[$storageId] = $storageId;
			}
		}

		foreach (array_chunk(array_values($storageIds), self::LEVEL_BATCH_SIZE) as $storageIdBatch)
		{
			Storage::loadBatchById($storageIdBatch, ['ROOT_OBJECT']);
		}
	}

	/**
	 * The rule the group copy of the list has always written by - the personal quota for the sum of the
	 * selection, weighed as an upload of the user - plus the two the single verdict weighs.
	 */
	protected function selectionSpaceAllows(Storage $targetStorage, int $size): bool
	{
		return $this->personalQuotaAllows($targetStorage, $size)
			&& $this->quotaAllows($size)
			&& $this->isPossibleToUpload($targetStorage, $size)
		;
	}

	protected function personalQuotaAllows(Storage $targetStorage, int $size): bool
	{
		return File::findPersonalQuotaErrorForSize(
			$targetStorage,
			$size,
			[WriteOperationContext::OPTION_KEY => WriteOperationContext::UserUpload],
		) === null;
	}

	/**
	 * Whether any of the names is taken in the folder: one query over the keys of the names and one over the
	 * rows stored before the key appeared, whatever the number of the names.
	 *
	 * @param string[] $names
	 */
	protected function isAnyNameTaken(array $names, Folder $realTargetFolder): bool
	{
		$parameters = [
			'select' => ['ID', 'NAME'],
			'filter' => ['=PARENT_ID' => (int)$realTargetFolder->getId()],
		];

		$keys = array_map(static fn(string $name): string => ObjectNameKey::of($name), $names);
		$rowWithKey = ObjectTable::getList([
			...$parameters,
			'filter' => [...$parameters['filter'], '@NAME_KEY' => $keys],
			'limit' => 1,
		])->fetch();
		if ($rowWithKey)
		{
			return true;
		}

		$keySet = array_flip($keys);
		$rowsWithoutKey = ObjectTable::getList(ObjectNameKey::buildParametersWithoutKey($parameters, $names));
		while ($row = $rowsWithoutKey->fetch())
		{
			if (isset($keySet[ObjectNameKey::of((string)$row['NAME'])]))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The batch twin of Folder::getSize over every folder given at once: one aggregate grouped by the folder
	 * whatever their number. The rules are the ones of getSize - the files of the whole subtree of the real
	 * folder, the deleted ones left out, counted against the storage of the folder given, so a link weighs
	 * what getSize() of the link weighs. A folder holding no file weighs zero.
	 *
	 * @param Folder[] $folders
	 * @return array<int, int> sizes by the id of the folder given
	 */
	protected function loadFolderSizes(array $folders): array
	{
		$realIds = [];
		$storageIds = [];
		foreach ($folders as $folder)
		{
			$realId = (int)$folder->getRealObjectId();
			$storageId = (int)$folder->getStorageId();
			$realIds[$realId] = $realId;
			$storageIds[$storageId] = $storageId;
		}

		if (empty($realIds))
		{
			return [];
		}

		$rows = FileTable::query()
			->registerRuntimeField('', new ExpressionField('FILE_SIZE', 'SUM(%s)', 'SIZE'))
			->setSelect(['ANCESTOR_ID' => 'PATH_CHILD.PARENT_ID', 'STORAGE_ID', 'FILE_SIZE'])
			->whereIn('PATH_CHILD.PARENT_ID', array_values($realIds))
			->whereIn('STORAGE_ID', array_values($storageIds))
			->where('TYPE', FileTable::TYPE)
			->where('DELETED_TYPE', FileTable::DELETED_TYPE_NONE)
			->exec()
		;

		$sizesByStorage = [];
		while ($row = $rows->fetch())
		{
			$sizesByStorage[(int)$row['ANCESTOR_ID']][(int)$row['STORAGE_ID']] = (int)$row['FILE_SIZE'];
		}

		$sizes = [];
		foreach ($folders as $folder)
		{
			$sizes[(int)$folder->getId()] =
				$sizesByStorage[(int)$folder->getRealObjectId()][(int)$folder->getStorageId()] ?? 0;
		}

		return $sizes;
	}

	protected function quotaAllows(int $size): bool
	{
		return (bool)(new \CDiskQuota())->checkDiskQuota($size);
	}

	protected function isPossibleToUpload(Storage $storage, int $size): bool
	{
		return (bool)$storage->isPossibleToUpload($size);
	}

	protected function getSecurityContext(Storage $storage): SecurityContext
	{
		return $storage->getSecurityContext($this->userId);
	}

	/**
	 * Every rule of a destination is weighed against the folder it points at: moveTo(), copyTo() and
	 * addSubFolder() of a link work on its real folder, so the storage of that folder answers for the
	 * tariff, the rights and the free space, and its id is the one the object ends up under. A folder
	 * that is not a link is its own real folder and costs no query at all.
	 */
	private function resolveRealFolder(Folder $targetFolder): ?Folder
	{
		$realFolder = $this->resolveRealObject($targetFolder);

		return $realFolder instanceof Folder ? $realFolder : null;
	}

	/**
	 * A link of a level takes its real object out of the batch read for the whole level, the null of a broken
	 * link included: the batch was asked about it and answered, so the answer is taken as it is. Only a link
	 * the batch was never asked about keeps the load of its own - a link asked apart from a level, which is
	 * the single path of check, apply and the creation of a folder.
	 */
	private function resolveRealObject(Folder $targetFolder): ?BaseObject
	{
		if (!$targetFolder->isLink())
		{
			return $targetFolder;
		}

		$realObjectId = (int)$targetFolder->getRealObjectId();
		if (array_key_exists($realObjectId, $this->levelRealObjects))
		{
			return $this->levelRealObjects[$realObjectId];
		}

		return $this->loadRealObject($targetFolder);
	}

	/**
	 * The ancestors prefetched belong to the folders that really live where they were read: under the parent
	 * of the level, or at the place the search found them. A link stands for a folder kept elsewhere, and the
	 * ancestors of that folder are its own: they come out of the batch read for the links of the level.
	 * Outside a level, and for a link the batch does not hold, the chain is asked for by itself, the way the
	 * per-folder path asks for it.
	 *
	 * @return int[]
	 */
	private function resolveAncestorIds(Folder $targetFolder, Folder $realTargetFolder): array
	{
		$realTargetId = (int)$realTargetFolder->getId();
		if ((int)$targetFolder->getId() !== $realTargetId)
		{
			return $this->levelLinkAncestorIds[$realTargetId] ?? $this->loadAncestorIds($realTargetId);
		}

		if ($this->levelAncestorIds !== null)
		{
			return $this->levelAncestorIds;
		}

		return $this->ancestorIdsByFolderId[$realTargetId] ?? $this->loadAncestorIds($realTargetId);
	}

	private function resolveNameConflict(BaseObject $object, Folder $realTargetFolder): bool
	{
		if ($this->levelBusyParentIds === null)
		{
			return $this->hasNameConflict($object, $realTargetFolder);
		}

		return isset($this->levelBusyParentIds[(int)$realTargetFolder->getId()]);
	}

	private function canRemoveSource(BaseObject $object): bool
	{
		$sourceStorage = $object->getStorage();
		if (!$sourceStorage instanceof Storage)
		{
			return false;
		}

		return $object->canMarkDeleted($this->getSecurityContext($sourceStorage));
	}

	/**
	 * The right to remove the source belongs to the object, not to the folder, so it is asked once for
	 * every folder the selection is weighed against.
	 */
	private function canRemoveSelectedSource(BaseObject $object): bool
	{
		return $this->removableSources[(int)$object->getId()] ??= $this->canRemoveSource($object);
	}

	private function hasEnoughSpaceForSelection(Storage $targetStorage, int $size): bool
	{
		$key = (int)$targetStorage->getId() . ':' . $size;

		return $this->selectionSpaceAllowance[$key] ??= $this->selectionSpaceAllows($targetStorage, $size);
	}

	/**
	 * Two objects of one selection landing under one name conflict with each other just as with a name the
	 * folder holds, and the executor resolves both the same way.
	 */
	private function resolveSelectionNameConflict(ObjectSelection $selection, Folder $realTargetFolder): bool
	{
		$namesByKey = [];
		foreach ($selection as $object)
		{
			$name = (string)$object->getName();
			$key = ObjectNameKey::of($name);
			if (isset($namesByKey[$key]))
			{
				return true;
			}

			$namesByKey[$key] = $name;
		}

		if (empty($namesByKey))
		{
			return false;
		}

		if ($this->levelSelectionBusyParentIds !== null)
		{
			return isset($this->levelSelectionBusyParentIds[(int)$realTargetFolder->getId()]);
		}

		return $this->isAnyNameTaken(array_values($namesByKey), $realTargetFolder);
	}

	private function holdsFolder(ObjectSelection $selection): bool
	{
		foreach ($selection as $object)
		{
			if ($object instanceof Folder)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The answer belongs to the pair of the storage and the size, not to the folder, and a whole level asks
	 * it about one and the same pair: the storage limit behind it sums the size of every version of the
	 * disk. So the answer is kept for the life of the resolver - one action over one object - the same way
	 * the ancestors and the taken names of the level are.
	 */
	private function hasEnoughSpace(BaseObject $object, Storage $targetStorage): bool
	{
		$size = $this->resolveObjectSize($object);
		if ($size <= 0)
		{
			return true;
		}

		$key = (int)$targetStorage->getId() . ':' . $size;
		if (!isset($this->spaceAllowance[$key]))
		{
			$this->spaceAllowance[$key] = $this->quotaAllows($size)
				&& $this->isPossibleToUpload($targetStorage, $size)
			;
		}

		return $this->spaceAllowance[$key];
	}

	/**
	 * The sum of the sizes of the objects of a selection. The folders not weighed yet are weighed at once
	 * (@see loadFolderSizes), so a selection costs one aggregate rather than one per folder; whether the
	 * answers are kept between the requests is the decision of the cache (@see ObjectSizeCache::getMany).
	 *
	 * @param BaseObject[] $objects
	 */
	private function resolveSelectionSize(array $objects): int
	{
		$foldersToWeigh = [];
		foreach ($objects as $object)
		{
			$objectId = (int)$object->getId();
			if ($object instanceof Folder && !isset($this->objectSizes[$objectId]))
			{
				$foldersToWeigh[$objectId] = $object;
			}
		}

		if (!empty($foldersToWeigh))
		{
			$sizes = $this->objectSizeCache->getMany(
				array_keys($foldersToWeigh),
				fn(array $ids): array => $this->loadFolderSizes(array_intersect_key($foldersToWeigh, array_flip($ids))),
			);
			foreach ($sizes as $objectId => $size)
			{
				$this->objectSizes[$objectId] = $size;
			}
		}

		$size = 0;
		foreach ($objects as $object)
		{
			$size += $this->resolveObjectSize($object);
		}

		return $size;
	}

	/**
	 * The size of a folder is an aggregate over its whole subtree (@see Folder::getSize), and it is the
	 * question every destination of the level asks about one and the same object. So it is asked once,
	 * before the answer about the free space is even keyed by it.
	 */
	private function resolveObjectSize(BaseObject $object): int
	{
		$objectId = (int)$object->getId();

		return $this->objectSizes[$objectId] ??= $this->weighObject($object);
	}

	/**
	 * A file answers out of a field of its own row, so it never reaches the cache. A folder answers with the
	 * aggregate over its subtree, and that one is the same number for every level of the popup, so it is
	 * kept between the requests (@see ObjectSizeCache).
	 *
	 * An empty subtree gives null (@see Folder::getSize), and it is made a zero here rather than inside the
	 * cache: what is kept has to be what the count itself would have answered.
	 */
	private function weighObject(BaseObject $object): int
	{
		if (!$object instanceof Folder)
		{
			return (int)$object->getSize();
		}

		return $this->objectSizeCache->get(
			(int)$object->getId(),
			static fn(): int => (int)$object->getSize(),
		);
	}
}
