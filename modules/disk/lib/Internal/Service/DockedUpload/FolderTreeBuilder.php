<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItem;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;
use Bitrix\Disk\Internal\Repository\DockedUpload\DockedUploadItemRepository;
use Bitrix\Disk\Internals\ObjectNameKey;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Main\DB\DuplicateEntryException;

/**
 * Disk-owned orchestration for a docked folder upload (API-04).
 *
 * It creates the missing sub-folders from a set of relative directory paths via
 * {@see Folder::addSubFolder()} (rights/names/events preserved) and binds each
 * queued file item to the folder that matches its relative path. It is idempotent
 * (an existing sub-folder is reused, never duplicated) and isolates failures to a
 * single branch - a failed branch does not roll back the folders already created.
 *
 * Existing folders are resolved level-by-level: {@see buildTree()} walks every
 * relative path in lock-step by depth and, for each level, batch-preloads the
 * live children of every parent touched at that level in two indexed queries
 * instead of one (or two) queries per path segment (perf fix S-7). Rights are re-checked
 * per actual parent right before a sub-folder is created under it - root-only
 * validation is not enough once the tree descends into pre-existing folders
 * that may carry overridden rights (security fix S-18) - and once more per
 * distinct destination before the files are bound ({@see classifyTargetFolders()}),
 * which is what covers a pre-existing folder REUSED as a destination rather than
 * created.
 */
class FolderTreeBuilder
{
	/**
	 * How many (parentId, name) pairs one level lookup may carry. A build never sees
	 * more paths than a stepper portion, so a real level fits in a single chunk; the
	 * cap only keeps a direct caller with a large path set off an unbounded IN list.
	 */
	protected const PRELOAD_CHUNK_SIZE = 200;

	public function __construct(
		private readonly FolderResolverService $folderResolver,
		private readonly DockedUploadItemRepository $itemRepository,
	)
	{
	}

	public function resolveWritableRoot(int $rootFolderId, int $userId): ?Folder
	{
		$root = $this->folderResolver->resolveTargetFolder($rootFolderId, $userId);
		if ($root === null)
		{
			return null;
		}

		$storage = $root->getStorage();
		if (
			$storage === null
			|| $this->folderResolver->isAccessRestricted($userId)
			|| $this->folderResolver->isCommonStorageBlocked($storage)
			|| !$this->folderResolver->canAddToFolder($root, $storage, $userId)
		)
		{
			return null;
		}

		return $root;
	}

	/**
	 * Creates every folder along each relative directory path under $root.
	 *
	 * Paths are walked in lock-step by depth (a "level"): at each depth the
	 * still-unresolved (parentId, name) pairs across every path are collected and
	 * resolved against existing live folders with a single batch query
	 * ({@see preloadExistingChildren()}), instead of a query per path segment.
	 * A parent created earlier within this same call is skipped from that batch
	 * lookup - it is brand new and cannot already own a matching child - so it
	 * goes straight to creation.
	 *
	 * @param string[] $relativePaths relative directory paths (segments joined by '/')
	 * @param array<string, int> $folderMap accumulated map ('' => rootId, dirPath => folderId)
	 * @return array{
	 *     createdFolders: array<int, array{relativePath: string, folderId: int}>,
	 *     folderMap: array<string, int>,
	 *     failedPaths: string[]
	 * }
	 */
	public function buildTree(Folder $root, array $relativePaths, int $userId, array $folderMap = []): array
	{
		$rootId = (int)$root->getId();
		$folderMap[''] = $rootId;
		$folderCache = [$rootId => $root];
		// Folders known to be brand new within this call - they cannot already own a
		// pre-existing child, so the next level skips the batch lookup for them.
		$freshFolderIds = [];
		$created = [];
		$failed = [];

		$branches = $this->initBranches($relativePaths, $rootId);
		$maxDepth = 0;
		foreach ($branches as $branch)
		{
			$maxDepth = max($maxDepth, count($branch['segments']));
		}

		for ($depth = 0; $depth < $maxDepth; $depth++)
		{
			// Group the segments still needing resolution at this depth by the
			// (parentId, name) pair they target, so identical pairs reached via
			// different branches are resolved (and, if missing, created) only once.
			$buckets = [];
			$namesByParent = [];

			foreach ($branches as $i => &$branch)
			{
				if (!$branch['alive'] || $depth >= count($branch['segments']))
				{
					continue;
				}

				$segment = $branch['segments'][$depth];
				$childPath = self::joinPath($branch['currentPath'], $segment);

				if (isset($folderMap[$childPath]))
				{
					$branch['parentId'] = $folderMap[$childPath];
					$branch['currentPath'] = $childPath;
					continue;
				}

				$parentId = $branch['parentId'];
				$groupKey = self::bucketKey($parentId, $segment);

				$buckets[$groupKey] ??= [
					'parentId' => $parentId,
					'segment' => $segment,
					'branchIndexes' => [],
				];
				$buckets[$groupKey]['branchIndexes'][] = $i;

				if (empty($freshFolderIds[$parentId]))
				{
					$namesByParent[$parentId][$segment] = true;
				}
			}
			unset($branch);

			if ($buckets === [])
			{
				continue;
			}

			foreach ($namesByParent as $parentId => $names)
			{
				$namesByParent[$parentId] = array_keys($names);
			}

			$existingMap = $this->preloadExistingChildren($namesByParent);

			foreach ($buckets as $groupKey => $bucket)
			{
				$parentId = $bucket['parentId'];
				$segment = $bucket['segment'];
				$branchIndexes = $bucket['branchIndexes'];

				$folderId = $existingMap[$groupKey] ?? null;
				if ($folderId === null)
				{
					$reportPath = self::joinPath($branches[$branchIndexes[0]]['currentPath'], $segment);
					$folderId = $this->createChildFolder($folderCache, $parentId, $segment, $userId, $created, $reportPath, $freshFolderIds);
				}

				foreach ($branchIndexes as $i)
				{
					$childPath = self::joinPath($branches[$i]['currentPath'], $segment);

					if ($folderId === null)
					{
						// Block only this branch; the rest of the tree keeps going.
						$failed[] = $childPath;
						$branches[$i]['alive'] = false;
						continue;
					}

					$folderMap[$childPath] = $folderId;
					$branches[$i]['parentId'] = $folderId;
					$branches[$i]['currentPath'] = $childPath;
				}
			}
		}

		return [
			'createdFolders' => $created,
			'folderMap' => $folderMap,
			'failedPaths' => $failed,
		];
	}

	/**
	 * Binds each queued file item nested under the folder-item to the folder matching
	 * its relative path. A top-level file (empty directory) binds to the root; a file
	 * whose directory branch failed to build (equal to or under a failed path) is
	 * marked failed rather than silently dropped at the root; a file whose branch is
	 * not yet built (a partial folderMap from a stepper portion) is left untouched so
	 * a later step binds it.
	 *
	 * File items are collected across the whole sub-tree, not only the folder-item's
	 * direct children, walking nested folder-items level by level (batched, no N+1).
	 *
	 * The failed ids are reported back alongside the bindings because the server verdict
	 * they carry has no other way to the client: they are absent from the bindings by
	 * construction, and a folder whose files ALL landed in a failed branch has no live
	 * sibling whose answer could carry the folder row instead. The caller turns them into
	 * the API-04 answer and the pull event.
	 *
	 * $storageId is the storage of the tree root, so a file whose own target had already
	 * vanished at enqueue inherits it here instead of reaching the client without a storage
	 * (the BizProc set is keyed by it). It rides along in the binding UPDATE, so the
	 * inheritance costs no query of its own.
	 *
	 * A folder-item with no file anywhere below it gets its own verdict here rather than one
	 * rolled up from leaves it does not have: when the build left the whole tree unbuilt
	 * ({@see self::isTreeUnbuilt()}) the folder row itself is marked failed, so a folder that
	 * never reached the Disk is not reported as created - a drained queue is what
	 * {@see \Bitrix\Disk\Internal\Dto\DockedUpload\ItemDto::fromFolderItem()} otherwise reads
	 * such a row by. Failed, never committed: the client cuts a big tree into several
	 * requests, and a terminal row would answer the remaining ones with a no-op.
	 *
	 * @param array<string, int> $folderMap
	 * @param string[] $failedPaths directory paths whose branch could not be created
	 * @return array{
	 *     bindings: array<int, array{itemId: int, targetFolderId: int}>,
	 *     failedItemIds: int[] every file left unplaced by this build, including the ones
	 *       a previous run had already marked - the verdict is reported on every call,
	 *       while the status write stays a one-off
	 * }
	 */
	public function bindFileItems(
		int $folderItemId,
		int $userId,
		int $rootFolderId,
		array $folderMap,
		array $failedPaths = [],
		?int $storageId = null,
	): array
	{
		$bindings = [];
		// Group the writes so a folder-tree build that routes many files into the same
		// folder collapses into one UPDATE per distinct folder (idsByTargetFolder) plus
		// one status UPDATE for the failed-branch files, instead of a per-row save().
		$idsByTargetFolder = [];
		$failedIds = [];
		$blockByFolderId = $this->classifyTargetFolders($folderMap, $rootFolderId, $userId, $storageId);
		/** @var array<string, int[]> $deniedIdsByCode */
		$deniedIdsByCode = [];
		// Every file this build could not place, whether or not it needed a write. The
		// two lists differ on a repeat call: the statuses are already there, so nothing is
		// written, yet the answer must still carry the verdict - the first call's pull
		// event is best-effort and may simply have been lost.
		$unplacedIds = [];
		$fileItems = $this->collectFileDescendants($folderItemId, $userId);
		foreach ($fileItems as $item)
		{
			$dir = self::directoryOf((string)$item->getRelativePath());

			if ($dir === '')
			{
				// A top-level file (no directory) always belongs at the root.
				$this->bindToFolder($item, $rootFolderId, $storageId, $bindings, $idsByTargetFolder);

				continue;
			}

			if (isset($folderMap[$dir]))
			{
				$targetFolderId = (int)$folderMap[$dir];
				$block = $blockByFolderId[$targetFolderId] ?? null;
				if ($block !== null)
				{
					// AC-055: the destination is refused, so the file must not start moving
					// bytes only to be refused again at commit time - and it is never bound,
					// so the answer carries no id of a folder its owner may not write into.
					$unplacedIds[] = (int)$item->getId();
					if ($item->getStatus() !== ItemStatus::Failed || $item->getErrorCode() !== $block)
					{
						$item->setStatus(ItemStatus::Failed)->setErrorCode($block);
						$deniedIdsByCode[$block][] = (int)$item->getId();
					}

					continue;
				}

				$this->bindToFolder($item, $targetFolderId, $storageId, $bindings, $idsByTargetFolder);

				continue;
			}

			if (self::isUnderFailedPath($dir, $failedPaths))
			{
				// The directory branch could not be created - fail the file instead of
				// silently rerouting it to the root (that would land files in the wrong
				// place). No target binding is emitted for a failed item.
				$unplacedIds[] = (int)$item->getId();
				if ($item->getStatus() !== ItemStatus::Failed)
				{
					$item
						->setStatus(ItemStatus::Failed)
						->setErrorCode(DisplayStatusResolver::ERROR_FOLDER_TREE_FAILED)
					;
					$failedIds[] = (int)$item->getId();
				}

				continue;
			}

			// The branch is neither built nor failed yet - a stepper portion has not
			// reached it. Leave the item untouched; a later step binds it.
		}

		foreach ($idsByTargetFolder as $targetFolderId => $ids)
		{
			$this->itemRepository->bindTargetFolderByIds($ids, $targetFolderId, $userId, $storageId);
		}

		if ($failedIds !== [])
		{
			$this->itemRepository->updateStatusByIds(
				$failedIds,
				ItemStatus::Failed,
				$userId,
				['ERROR_CODE' => DisplayStatusResolver::ERROR_FOLDER_TREE_FAILED],
			);
		}

		foreach ($deniedIdsByCode as $errorCode => $ids)
		{
			$this->itemRepository->updateStatusByIds(
				$ids,
				ItemStatus::Failed,
				$userId,
				['ERROR_CODE' => $errorCode],
			);
		}

		if ($fileItems === [] && self::isTreeUnbuilt($folderMap, $failedPaths))
		{
			$this->itemRepository->updateStatusByIds(
				[$folderItemId],
				ItemStatus::Failed,
				$userId,
				['ERROR_CODE' => DisplayStatusResolver::ERROR_FOLDER_TREE_FAILED],
			);
		}

		return [
			'bindings' => $bindings,
			'failedItemIds' => $unplacedIds,
		];
	}

	/**
	 * Whether the build left the tree entirely unbuilt: a branch was refused and not one
	 * directory of the tree is mapped - only the root is.
	 *
	 * A directory that already exists reaches $folderMap through a live lookup
	 * ({@see self::preloadExistingChildren()}) rather than from memory, so the map also
	 * carries what an earlier request built, next to what the accumulated map of a stepper
	 * job carries across portions. That is what keeps a PARTIAL build out: while any
	 * directory of the tree stands on the Disk, the folder is there and refusing its row
	 * would be a concrete lie.
	 *
	 * A build that was asked to create nothing refuses nothing either, hence the failed-path
	 * half: an empty map alone only says this call had no tree to walk.
	 *
	 * @param array<string, int> $folderMap
	 * @param string[] $failedPaths
	 */
	private static function isTreeUnbuilt(array $folderMap, array $failedPaths): bool
	{
		if ($failedPaths === [])
		{
			return false;
		}

		foreach (array_keys($folderMap) as $path)
		{
			if ((string)$path !== '')
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * The access verdict per DISTINCT destination folder of this build (AC-055, ERR-014).
	 *
	 * A path segment that already existed is resolved by a raw {@see ObjectTable} read with
	 * no rights predicate ({@see self::preloadExistingChildren()}), and hitting such a node
	 * skips {@see self::createChildFolder()} together with its per-parent canAdd check
	 * (S-18). Without this pass a file addressed at a pre-existing folder its owner may not
	 * write into would be bound, transfer its bytes and only be refused at commit time.
	 *
	 * One point covers every way a node was obtained - the preload, the recovery after a
	 * name collision, and the levels of an earlier stepper portion carried in $folderMap.
	 * The root is left out: {@see self::resolveWritableRoot()} has already gated it.
	 *
	 * @param array<string, int> $folderMap
	 * @return array<int, string> folder id => docked ERROR_CODE, only for the refused ones
	 */
	private function classifyTargetFolders(array $folderMap, int $rootFolderId, int $userId, ?int $storageId): array
	{
		if ($storageId === null)
		{
			// Every folder of a docked tree lives under the root, so the root storage is
			// what the gate is asked with. Without it there is nothing to ask; the root
			// gate of resolveWritableRoot() is what still holds.
			return [];
		}

		$targets = [];
		foreach ($folderMap as $folderId)
		{
			$folderId = (int)$folderId;
			if ($folderId > 0 && $folderId !== $rootFolderId)
			{
				$targets[$folderId] = $storageId;
			}
		}

		if ($targets === [])
		{
			return [];
		}

		$blocks = [];
		foreach ($this->folderResolver->classifyUploadTargets($targets, [], $userId) as $folderId => $errorCode)
		{
			$blocks[(int)$folderId] = (string)$errorCode;
		}

		return $blocks;
	}

	/**
	 * Marks every file of the sub-tree with the folder-tree verdict, for the build that
	 * could not even start: without a writable root NO branch of it will ever exist, so
	 * every file below is unplaced at once instead of branch by branch.
	 *
	 * Same write and same guard as the per-branch failure of {@see bindFileItems()} -
	 * terminal rows are kept out of the UPDATE - and the ids come back for the caller's
	 * verdict, which the async branch has no other way to deliver.
	 *
	 * A sub-tree with no file at all is the one case with no descendant to carry the
	 * verdict, so the folder row carries its own - the same write the unbuilt-tree branch
	 * of {@see bindFileItems()} makes. Unlike that branch this one needs no predicate:
	 * every caller reaches it having already declared the whole job failed. Without it the
	 * row keeps a non-terminal status nothing will ever move, and the reader of a drained
	 * queue takes a folder that was never created for one that was.
	 *
	 * @return int[] every FILE of the sub-tree, including the ones a previous run had
	 *   already marked: the verdict is reported on every call, while the status write
	 *   stays a one-off. The folder row itself is never among them - the caller renders
	 *   these as file DTOs.
	 */
	public function failUnplacedFiles(int $folderItemId, int $userId): array
	{
		$unplacedIds = [];
		$failedIds = [];
		foreach ($this->collectFileDescendants($folderItemId, $userId) as $item)
		{
			$unplacedIds[] = (int)$item->getId();
			if ($item->getStatus() !== ItemStatus::Failed)
			{
				$failedIds[] = (int)$item->getId();
			}
		}

		if ($failedIds !== [])
		{
			$this->itemRepository->updateStatusByIds(
				$failedIds,
				ItemStatus::Failed,
				$userId,
				['ERROR_CODE' => DisplayStatusResolver::ERROR_FOLDER_TREE_FAILED],
			);
		}

		if ($unplacedIds === [])
		{
			$this->itemRepository->updateStatusByIds(
				[$folderItemId],
				ItemStatus::Failed,
				$userId,
				['ERROR_CODE' => DisplayStatusResolver::ERROR_FOLDER_TREE_FAILED],
			);
		}

		return $unplacedIds;
	}

	/**
	 * @param array<int, array{itemId: int, targetFolderId: int}> $bindings
	 * @param array<int, int[]> $idsByTargetFolder ids to bind, grouped by target folder
	 */
	private function bindToFolder(
		DockedUploadItem $item,
		int $targetFolderId,
		?int $storageId,
		array &$bindings,
		array &$idsByTargetFolder,
	): void
	{
		$inheritsStorage = $storageId !== null && $item->getStorageId() === null;

		if ((int)$item->getTargetFolderId() !== $targetFolderId || $inheritsStorage)
		{
			// Keep the in-memory value in sync (as the old per-row save did) and queue
			// the id for the grouped UPDATE - only when there is something to write.
			$item->setTargetFolderId($targetFolderId);
			if ($inheritsStorage)
			{
				$item->setStorageId($storageId);
			}
			$idsByTargetFolder[$targetFolderId][] = (int)$item->getId();
		}

		$bindings[] = [
			'itemId' => (int)$item->getId(),
			'targetFolderId' => $targetFolderId,
		];
	}

	/**
	 * Collects every file item in the sub-tree rooted at $folderItemId by walking the
	 * nested folder-items level by level with a single batched query per level, so a
	 * deep folder tree never degrades into an N+1 per parent.
	 *
	 * @return DockedUploadItem[]
	 */
	private function collectFileDescendants(int $folderItemId, int $userId): array
	{
		$fileItems = [];
		$level = [$folderItemId];
		$seenFolders = [$folderItemId => true];

		while ($level !== [])
		{
			$nextLevel = [];
			foreach ($this->itemRepository->getByParentItems($level, $userId) as $child)
			{
				if (!$child->isFolder())
				{
					$fileItems[] = $child;

					continue;
				}

				$childId = (int)$child->getId();
				if (!isset($seenFolders[$childId]))
				{
					$seenFolders[$childId] = true;
					$nextLevel[] = $childId;
				}
			}

			$level = $nextLevel;
		}

		return $fileItems;
	}

	/**
	 * A directory is under a failed branch when it equals a failed path or descends
	 * from one on a segment boundary (so "a/b" is under "a" but "a/bc" is not).
	 *
	 * @param string[] $failedPaths
	 */
	private static function isUnderFailedPath(string $dir, array $failedPaths): bool
	{
		foreach ($failedPaths as $failed)
		{
			if ($dir === $failed || str_starts_with($dir, $failed . '/'))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string[] $relativePaths
	 * @return array<int, array{segments: string[], currentPath: string, parentId: int, alive: bool}>
	 */
	private function initBranches(array $relativePaths, int $rootId): array
	{
		$branches = [];
		foreach ($relativePaths as $relativePath)
		{
			$segments = self::segments($relativePath);
			if ($segments === [])
			{
				continue;
			}

			$branches[] = [
				'segments' => $segments,
				'currentPath' => '',
				'parentId' => $rootId,
				'alive' => true,
			];
		}

		return $branches;
	}

	/**
	 * Batch-preloads live folders under the given parents for one tree level into
	 * a (parentId, name) => folderId map - the perf fix for S-7: earlier every
	 * existing path segment cost its own findChildFolderId round trip (up to two
	 * queries: an ObjectNameService uniqueness probe plus a type/deleted-type
	 * re-check). buildTree() now resolves a whole tree level with two indexed queries.
	 *
	 * The requested names bound that query as well (B6), so a fat parent - a root
	 * already holding thousands of sub-folders - is no longer read out whole for
	 * the handful of names one level actually asks about. The two IN lists are
	 * built from a chunk of (parentId, name) pairs, which caps both of them at
	 * once: neither the parents of a level nor the client-supplied names can grow
	 * the query without bound. A chunk still matches every parent against every
	 * name, so the pair itself is verified in PHP afterwards.
	 *
	 * Candidates are looked up the way {@see ObjectNameKey::findSameName()} does it,
	 * so a folder the (PARENT_ID, NAME_KEY) unique index would reject as a duplicate
	 * is always found: by the key, then by the name among the rows whose key is not
	 * filled yet ({@see ObjectNameKey::buildParametersWithoutKey()}). The database only
	 * narrows the latter down, so for them name equality is decided in PHP by {@see ObjectNameKey}: the case does not
	 * matter, diacritics do. An exact name is preferred over a case variant.
	 *
	 * @param array<int, string[]> $namesByParent parentId => requested child names
	 * @return array<string, int> "parentId\0name" (requested name) => folderId
	 */
	protected function preloadExistingChildren(array $namesByParent): array
	{
		// A purely numeric segment ("2024") comes back from array_keys() as an int,
		// hence the cast - both the query and the pair key expect a string name.
		$pairs = [];
		foreach ($namesByParent as $parentId => $names)
		{
			foreach ($names as $name)
			{
				$pairs[] = [$parentId, (string)$name];
			}
		}

		if ($pairs === [])
		{
			return [];
		}

		// Index every matched child once per parent, so a requested name resolves in
		// O(1). The first row of a given name wins, mirroring the old break on the
		// first match.
		$exactIndex = [];
		$keyIndex = [];
		foreach (array_chunk($pairs, static::PRELOAD_CHUNK_SIZE) as $chunk)
		{
			$parentIds = array_values(array_unique(array_column($chunk, 0)));
			$names = array_values(array_unique(array_column($chunk, 1)));
			$parameters = [
				'select' => ['ID', 'PARENT_ID', 'NAME', 'NAME_KEY'],
				'filter' => [
					'@PARENT_ID' => $parentIds,
					'=TYPE' => ObjectTable::TYPE_FOLDER,
					'=DELETED_TYPE' => ObjectTable::DELETED_TYPE_NONE,
				],
			];
			$queries = [
				[
					...$parameters,
					'filter' => [
						...$parameters['filter'],
						'@NAME_KEY' => array_values(array_unique(array_map(ObjectNameKey::of(...), $names))),
					],
				],
				ObjectNameKey::buildParametersWithoutKey($parameters, $names),
			];
			foreach ($queries as $queryParameters)
			{
				foreach (ObjectTable::getList($queryParameters) as $row)
				{
					$parentId = (int)$row['PARENT_ID'];
					$name = (string)$row['NAME'];
					$exactIndex[$parentId][$name] ??= (int)$row['ID'];
					$keyIndex[$parentId][$row['NAME_KEY'] ?? ObjectNameKey::of($name)] ??= (int)$row['ID'];
				}
			}
		}

		$map = [];
		foreach ($pairs as [$parentId, $name])
		{
			$folderId = $exactIndex[$parentId][$name] ?? $keyIndex[$parentId][ObjectNameKey::of($name)] ?? null;
			if ($folderId !== null)
			{
				$map[self::bucketKey($parentId, $name)] = $folderId;
			}
		}

		return $map;
	}

	/**
	 * @param array<int, Folder> $folderCache
	 * @param array<int, array{relativePath: string, folderId: int}> $created
	 * @param array<int, true> $freshFolderIds
	 */
	private function createChildFolder(
		array &$folderCache,
		int $parentId,
		string $name,
		int $userId,
		array &$created,
		string $childPath,
		array &$freshFolderIds,
	): ?int
	{
		$parent = $folderCache[$parentId] ?? Folder::loadById($parentId, ['STORAGE']);
		if ($parent === null)
		{
			return null;
		}
		$folderCache[$parentId] = $parent;

		// Rights are re-checked on the *actual* immediate parent, not only on the
		// tree root: a nested pre-existing folder can carry rights overridden from
		// its ancestors, and resolveWritableRoot() only ever validated the root
		// (security fix S-18). A denial here blocks only this branch, item-level.
		$storage = $parent->getStorage();
		if ($storage === null || !$this->folderResolver->canAddToFolder($parent, $storage, $userId))
		{
			return null;
		}

		$hadNameCollision = false;
		try
		{
			$subFolder = $parent->addSubFolder([
				'NAME' => $name,
				'CREATED_BY' => $userId,
			], [], false);
		}
		catch (DuplicateEntryException)
		{
			$subFolder = null;
			$hadNameCollision = true;
		}

		if ($subFolder === null)
		{
			// A concurrent request (e.g. a second browser tab) can create this exact
			// sub-folder after preloadExistingChildren ran but before this write, so
			// addSubFolder reports a name collision even though the folder now exists.
			// Reuse the live sibling to keep the build idempotent instead of failing
			// the branch - it is not counted as created and not marked fresh, so the
			// next level still batch-preloads its (possibly pre-existing) children.
			// Any other failure (rights, etc.) stays a real branch failure.
			if ($hadNameCollision || $parent->getErrorByCode(BaseObject::ERROR_NON_UNIQUE_NAME) !== null)
			{
				return $this->resolveExistingChildId($parentId, $name);
			}

			return null;
		}

		$folderId = (int)$subFolder->getId();
		$folderCache[$folderId] = $subFolder;
		$freshFolderIds[$folderId] = true;
		$created[] = [
			'relativePath' => $childPath,
			'folderId' => $folderId,
		];

		return $folderId;
	}

	/**
	 * Re-resolves a live child folder by (parentId, name) with the same query and
	 * name matching as {@see preloadExistingChildren()} - used to recover the folder
	 * a concurrent request created between preload and this write.
	 */
	private function resolveExistingChildId(int $parentId, string $name): ?int
	{
		return $this->preloadExistingChildren([$parentId => [$name]])[self::bucketKey($parentId, $name)] ?? null;
	}

	private static function bucketKey(int $parentId, string $name): string
	{
		return $parentId . "\0" . $name;
	}

	private static function joinPath(string $currentPath, string $segment): string
	{
		return $currentPath === '' ? $segment : $currentPath . '/' . $segment;
	}

	/**
	 * @return string[]
	 */
	private static function segments(string $path): array
	{
		return array_values(array_filter(
			explode('/', trim($path, '/')),
			static fn($segment) => $segment !== '',
		));
	}

	private static function directoryOf(string $path): string
	{
		$segments = self::segments($path);
		array_pop($segments);

		return implode('/', $segments);
	}
}
