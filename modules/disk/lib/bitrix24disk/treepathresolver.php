<?php

namespace Bitrix\Disk\Bitrix24Disk;

use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Type\DateTime;

/**
 * Closure-table-based path resolver for Bitrix24 desktop snapshot flow.
 *
 * Replaces the full in-memory tree build (NewDiskStorage::loadTree / buildTree) with
 * targeted queries against b_disk_object_path. Used to avoid the heavy 3-table JOIN
 * with EXISTS rights-check that fires on every snapshot under active load.
 *
 * Scope: one resolver instance lives for the duration of a single snapshot request.
 * @internal
 */
final class TreePathResolver
{
	/**
	 * Folder codes that NewDiskStorage::buildSelfTree() excludes from $treeData.
	 * Any object whose chain (including itself) crosses such a folder has a null
	 * path in baseline and must do so here too. Currently only the user's
	 * "Uploaded files" folder is filtered; add new codes here if upstream changes.
	 */
	private const EXCLUDED_CODES = ['FOR_UPLOADED_FILES'];

	private int $storageId;
	private int $rootObjectId;
	private SecurityContext $securityContext;
	private Connection $connection;

	/** @var array<int, array{id:int,name:string,parentId:int,realObjectId:int,createTime:?DateTime,isReplica:bool}>|null */
	private ?array $symlinks = null;

	/**
	 * realObjectId => list of candidate symlink ids (ascending id) that route this real
	 * object. Usually one, but a foreign target shared to the user more than once has
	 * several; {@see resolveRoutedLinkPath()} picks the highest-id one ("last wins").
	 * @var array<int, int[]>
	 */
	private array $realToLink = [];

	/** @var array<int, ?string> linkId => path (memoized) */
	private array $linkPathCache = [];

	/** @var array<int, bool> linkId => true while being resolved (cycle guard) */
	private array $linkResolutionInProgress = [];

	/** @var array<int, ?string> objectId => path */
	private array $objectPathCache = [];

	/** @var array<int, ?string> objectId => path (preferDirect=true variant) */
	private array $objectPathCacheDirect = [];

	/**
	 * @var array<int, array<int, array{id:int,name:string,realObjectId:int,depth:int,accessible:bool}>>
	 *   objectId => list of ancestor rows ordered by DEPTH_LEVEL ASC (parent first)
	 */
	private array $ancestorsCache = [];

	public function __construct(int $storageId, int $rootObjectId, SecurityContext $securityContext)
	{
		$this->storageId = $storageId;
		$this->rootObjectId = $rootObjectId;
		$this->securityContext = $securityContext;
		$this->connection = Application::getConnection();
	}

	/**
	 * Pre-fetch ancestor rows for a page of object IDs in a single SQL.
	 * Subsequent resolve() calls are served from the cache.
	 */
	public function preload(array $objectIds): void
	{
		if (!$objectIds)
		{
			return;
		}

		$missing = [];
		foreach ($objectIds as $id)
		{
			$id = (int)$id;
			if ($id > 0 && !array_key_exists($id, $this->ancestorsCache))
			{
				$missing[$id] = true;
			}
		}

		if (!$missing)
		{
			return;
		}

		$this->fetchAncestorsBatch(array_keys($missing));
	}

	public function resolve(int $objectId, bool $preferDirect = false): ?string
	{
		if ($objectId <= 0)
		{
			return null;
		}

		if ($preferDirect && array_key_exists($objectId, $this->objectPathCacheDirect))
		{
			return $this->objectPathCacheDirect[$objectId];
		}
		if (!$preferDirect && array_key_exists($objectId, $this->objectPathCache))
		{
			return $this->objectPathCache[$objectId];
		}

		$path = $this->buildPath($objectId, $preferDirect);

		// Match original NewDiskStorage::getPath() semantics:
		// `return $path ?: $this->treeData[$id]->getPath();` - when preferDirect produces
		// no result, fall back to the regular (non-direct) resolution.
		if ($path === null && $preferDirect)
		{
			$path = $this->buildPath($objectId, false);
		}

		if ($preferDirect)
		{
			$this->objectPathCacheDirect[$objectId] = $path;
		}
		else
		{
			$this->objectPathCache[$objectId] = $path;
		}

		return $path;
	}

	/**
	 * Returns symlinks of the user as TreeNode[] sorted by id ASC.
	 * Matches the iteration order of getSymlinkFoldersSortedById()'s CustomHeap,
	 * whose comparator returns +1 when treeNode1.id < treeNode2.id - i.e. smaller
	 * ids get higher priority and come out of the heap first. snapshotFromLinks()
	 * relies on this order via `$link->id >= $expectedFirstId` to resume pagination.
	 *
	 * @return TreeNode[]
	 */
	public function getSymlinkNodesSortedById(): array
	{
		$this->ensureSymlinks();

		$nodes = [];
		foreach ($this->symlinks as $row)
		{
			$node = new TreeNode($row['id'], $row['name'], $row['parentId'], $row['realObjectId']);
			if ($row['createTime'] instanceof DateTime)
			{
				$node->setCreateDate($row['createTime']);
			}
			if ($row['isReplica'])
			{
				$node->markAsReplica();
			}
			$nodes[] = $node;
		}

		usort($nodes, static fn(TreeNode $a, TreeNode $b) => $a->id <=> $b->id);

		return $nodes;
	}

	public function reset(): void
	{
		$this->symlinks = null;
		$this->realToLink = [];
		$this->linkPathCache = [];
		$this->linkResolutionInProgress = [];
		$this->objectPathCache = [];
		$this->objectPathCacheDirect = [];
		$this->ancestorsCache = [];
		$this->objectNameCache = [];
		$this->excludedObjectIds = [];
	}

	/**
	 * Termination without a depth cap: buildPath() never recurses into itself directly - the only
	 * recursion is via resolveRoutedLinkPath()->resolveLinkPath()->buildPath(parentId), and each
	 * resolveLinkPath() is cycle-guarded by $linkResolutionInProgress (a link is resolved at most
	 * once per chain). Ancestor walking reads the closure table, which is acyclic by construction.
	 * So the recursion is bounded by the finite number of symlinks - matching baseline getPath(),
	 * which likewise has no numeric cap and only guards revisits via TreeNode::$__pathNodes.
	 */
	private function buildPath(int $objectId, bool $preferDirect): ?string
	{
		if ($objectId === $this->rootObjectId)
		{
			return '/';
		}

		$this->ensureSymlinks();

		// Ensure ancestor + name + code data is loaded for this id.
		$ancestors = $this->getAncestors($objectId);

		// Mirror NewDiskStorage::buildSelfTree() behaviour: folders carrying an excluded
		// CODE (e.g. "Uploaded files") are skipped from the tree, which causes baseline
		// getPath() to return null for them and their descendants. We replicate that.
		if (isset($this->excludedObjectIds[$objectId]))
		{
			return null;
		}

		// If the object ITSELF is the real target of one of user's symlinks, its logical path
		// equals that link's path verbatim - matches original TreeNode::getPath() shortcut:
		//   `if ($this->__link) return $this->__link->getPath();`
		// preferDirect skips this shortcut (matches getPathWithoutFirstLink semantics for the
		// first transformation; the caller-level fallback re-runs with preferDirect=false).
		if (!$preferDirect && isset($this->realToLink[$objectId]))
		{
			return $this->resolveRoutedLinkPath($this->realToLink[$objectId]);
		}

		if (!$ancestors)
		{
			return null;
		}

		// Get this object's own name (the deepest ancestor is the closest to root;
		// we need the object's own name separately because closure-table rows describe ancestors,
		// not the object itself). We pull it from the cache built alongside ancestors.
		$objectName = $this->getObjectName($objectId);
		if ($objectName === null)
		{
			return null;
		}

		// ancestors ordered from direct-parent (depth=1) upward to root.
		// We walk in this order - first match wins (matches original getPath() bottom-up traversal).
		$intermediateNames = []; // names of folders between the entry point (root or link) and X, top-down

		foreach ($ancestors as $idx => $ancestor)
		{
			$ancId = $ancestor['id'];
			$ancRealId = $ancestor['realObjectId'];

			// Symlink reroute: ancestor IS the real object of some user symlink.
			// NOTE: reroute applies at EVERY ancestor depth, including the direct parent
			// (DEPTH_LEVEL=1). TreeNode::getPathWithoutFirstLink() differs from getPath()
			// ONLY by skipping the object's OWN __link alias (handled at buildPath() start via
			// the `!$preferDirect && realToLink[$objectId]` shortcut); the parent chain is still
			// walked through the full getPath(), which honours each ancestor's own __link. So
			// preferDirect must NOT suppress reroute at the direct parent.
			$canReroute = isset($this->realToLink[$ancId]);
			if ($canReroute)
			{
				$linkPath = $this->resolveRoutedLinkPath($this->realToLink[$ancId]);
				if ($linkPath === null)
				{
					return null;
				}

				return $this->compose($linkPath, $intermediateNames, $objectName);
			}

			if ($ancId === $this->rootObjectId)
			{
				return $this->compose('/', $intermediateNames, $objectName);
			}

			// Fail closed: an intermediate ancestor the user cannot read (foreign storage, no
			// rights) or that is soft-deleted is absent from baseline's $treeData, which makes
			// baseline getPath() return null. Emitting a path here would leak that folder's name
			// and surface an object baseline hid. The reroute/root checks above run first, so an
			// authorised symlink entry point is never blocked by this.
			if (!$ancestor['accessible'])
			{
				return null;
			}

			// Continue upward; the current ancestor is an intermediate folder whose name
			// will appear in the final path BELOW the entry point.
			array_unshift($intermediateNames, $ancestor['name']);
		}

		return null;
	}

	private function compose(string $entryPath, array $intermediateNames, string $objectName): string
	{
		// $entryPath ends with '/' (it is either '/' or a fully-qualified path from a symlink).
		$middle = '';
		if ($intermediateNames)
		{
			$middle = implode('/', $intermediateNames) . '/';
		}

		return $entryPath . $middle . $objectName . '/';
	}

	/**
	 * Resolve the path of a real object routed through one of its symlinks.
	 *
	 * A foreign target can have several symlinks pointing at it (same folder shared twice).
	 * Baseline routes strictly "last wins": buildTree()'s setLink() loop (and the
	 * 's'.realObjectId alias in fillTreeData()) unconditionally leaves the LAST candidate in
	 * iteration order, even when that candidate's own path later resolves to null - it does NOT
	 * fall back to an earlier duplicate. We must not fall back either, or we would surface a
	 * subtree stock hides. $linkIds is kept ascending, so the last element is the winner.
	 *
	 * NB: baseline's "last" is the last row in an unordered buildSelfTree()/buildTreeFromLink()
	 * query (no ORDER BY), i.e. DB-order-dependent and therefore not deterministic across engines.
	 * We pick the highest id as a stable representative of that class of outcomes rather than
	 * reproducing a non-deterministic scan order.
	 *
	 * @param int[] $linkIds candidate symlink ids (ascending) routing the same real object
	 */
	private function resolveRoutedLinkPath(array $linkIds): ?string
	{
		if (!$linkIds)
		{
			return null;
		}

		return $this->resolveLinkPath($linkIds[count($linkIds) - 1]);
	}

	private function resolveLinkPath(int $linkId): ?string
	{
		if (array_key_exists($linkId, $this->linkPathCache))
		{
			return $this->linkPathCache[$linkId];
		}
		if (isset($this->linkResolutionInProgress[$linkId]))
		{
			return null;
		}

		$link = $this->symlinks[$linkId] ?? null;
		if (!$link)
		{
			return $this->linkPathCache[$linkId] = null;
		}

		$this->linkResolutionInProgress[$linkId] = true;

		try
		{
			if ($link['parentId'] === $this->rootObjectId)
			{
				$path = '/' . $link['name'] . '/';
			}
			else
			{
				$parentPath = $this->buildPath($link['parentId'], false);
				$path = $parentPath === null ? null : $parentPath . $link['name'] . '/';
			}

			$this->linkPathCache[$linkId] = $path;
			return $path;
		}
		finally
		{
			unset($this->linkResolutionInProgress[$linkId]);
		}
	}

	/**
	 * @return array<int, array{id:int,name:string,realObjectId:int,depth:int,accessible:bool}>
	 */
	private function getAncestors(int $objectId): array
	{
		if (!array_key_exists($objectId, $this->ancestorsCache))
		{
			$this->fetchAncestorsBatch([$objectId]);
		}

		return $this->ancestorsCache[$objectId] ?? [];
	}

	private function getObjectName(int $objectId): ?string
	{
		// The object itself appears as PARENT_ID in its descendants' closure rows, OR
		// can be fetched via a self-row (PARENT_ID = OBJECT_ID, DEPTH_LEVEL = 0).
		// For efficiency we cache it from the batch fetch.
		if (!array_key_exists($objectId, $this->objectNameCache))
		{
			$this->fetchAncestorsBatch([$objectId]); // will populate name too
		}

		return $this->objectNameCache[$objectId] ?? null;
	}

	/** @var array<int, ?string> objectId => NAME */
	private array $objectNameCache = [];

	/** @var array<int, true> objectIds whose chain hits an EXCLUDED_CODES folder */
	private array $excludedObjectIds = [];

	private function fetchAncestorsBatch(array $objectIds): void
	{
		$objectIds = array_values(array_unique(array_map('intval', $objectIds)));
		$objectIds = array_filter($objectIds, static fn(int $i) => $i > 0);
		if (!$objectIds)
		{
			return;
		}

		// Mark every requested id with an empty cache entry so subsequent calls don't re-query.
		foreach ($objectIds as $id)
		{
			if (!array_key_exists($id, $this->ancestorsCache))
			{
				$this->ancestorsCache[$id] = [];
			}
			if (!array_key_exists($id, $this->objectNameCache))
			{
				$this->objectNameCache[$id] = null;
			}
		}

		$inList = implode(',', $objectIds);

		// Pull ancestor chain + DEPTH_LEVEL=0 self-row (which carries the object's own NAME).
		// DEPTH_LEVEL=0 self-row: PARENT_ID = OBJECT_ID. Otherwise: ancestors.
		// CODE is included so we can detect SpecificFolder::CODE_FOR_UPLOADED_FILES - that
		// folder is excluded from baseline's treeData in buildSelfTree(), so any object under
		// it has a null path in baseline. We mirror this by returning null whenever any
		// ancestor (or the object itself) carries an excluded code.
		$deletedTypeNone = (int)ObjectTable::DELETED_TYPE_NONE;
		$storageId = $this->storageId;

		// HAS_ACCESS drives the fail-closed accessibility check applied to every INTERMEDIATE
		// ancestor in buildPath(). Parity with baseline:
		//   * Foreign (symlinked) subtrees are built in baseline with a rights predicate
		//     (buildTreeFromLink / buildTreeFromFirstLevelLinks: `AND ({$rightExists})`), so an
		//     ancestor the user cannot read is simply absent from $treeData -> getPath() == null.
		//     Here the row is still fetched, but marked inaccessible so buildPath() returns null
		//     instead of composing a path that would leak the unauthorised folder's name.
		//   * Own-storage folders carry NO rights filter in baseline (buildSelfTree loads the
		//     whole personal storage), so STORAGE_ID match short-circuits to accessible.
		//   * Soft-deleted folders are excluded from every baseline build query; a live descendant
		//     under a trashed ancestor is unreachable in practice (Folder::markDeleted cascades
		//     DELETED_TYPE to the whole subtree), but we keep the check for exact parity.
		$rightSql = $this->securityContext->getSqlExpressionForList('o.ID', 'o.CREATED_BY');
		$sql = "
			SELECT
				p.OBJECT_ID, p.DEPTH_LEVEL,
				o.ID, o.NAME, o.REAL_OBJECT_ID, o.DELETED_TYPE, o.CODE, o.STORAGE_ID,
				CASE
					WHEN o.STORAGE_ID = {$storageId} THEN 1
					WHEN {$rightSql} THEN 1
					ELSE 0
				END AS HAS_ACCESS
			FROM b_disk_object_path p
			INNER JOIN b_disk_object o ON o.ID = p.PARENT_ID
			WHERE p.OBJECT_ID IN ({$inList})
			ORDER BY p.OBJECT_ID, p.DEPTH_LEVEL ASC
		";

		foreach ($this->connection->query($sql) as $row)
		{
			$oid = (int)$row['OBJECT_ID'];
			$depth = (int)$row['DEPTH_LEVEL'];
			$code = (string)($row['CODE'] ?? '');

			// Baseline excludes FOR_UPLOADED_FILES only in buildSelfTree() - i.e. ONLY for the
			// user's own storage. buildTreeFromLink()/buildTreeFromFirstLevelLinks() carry no such
			// filter, so a foreign shared folder that happens to hold this CODE (e.g. another
			// storage's "Uploaded files") stays in baseline's $treeData and keeps a valid path.
			// Gate the exclusion on own storage to preserve that: without the STORAGE_ID check we
			// would null out a foreign shared subtree that stock still enumerates.
			if ((int)$row['STORAGE_ID'] === $this->storageId && in_array($code, self::EXCLUDED_CODES, true))
			{
				$this->excludedObjectIds[$oid] = true;
			}

			if ($depth === 0)
			{
				// Self-row: this is the object itself; capture its NAME.
				if ((int)$row['DELETED_TYPE'] === $deletedTypeNone)
				{
					$this->objectNameCache[$oid] = (string)$row['NAME'];
				}
				continue;
			}

			$this->ancestorsCache[$oid][] = [
				'id' => (int)$row['ID'],
				'name' => (string)$row['NAME'],
				'realObjectId' => (int)$row['REAL_OBJECT_ID'],
				'depth' => $depth,
				'accessible' => (
					(int)$row['DELETED_TYPE'] === $deletedTypeNone
					&& (int)$row['HAS_ACCESS'] === 1
				),
			];
		}
	}

	private function ensureSymlinks(): void
	{
		if ($this->symlinks !== null)
		{
			return;
		}

		$this->symlinks = [];
		$this->realToLink = [];

		$storageId = $this->storageId;
		$deletedTypeNone = (int)ObjectTable::DELETED_TYPE_NONE;
		$typeFolder = (int)ObjectTable::TYPE_FOLDER;

		// First-level symlinks: rows in user's own storage where REAL_OBJECT_ID != ID.
		// Uses index IX_DISK_O_4 (STORAGE_ID, DELETED_TYPE, TYPE).
		$sqlFirstLevel = "
			SELECT ID, NAME, PARENT_ID, REAL_OBJECT_ID, CREATE_TIME
			FROM b_disk_object
			WHERE STORAGE_ID = {$storageId}
			  AND DELETED_TYPE = {$deletedTypeNone}
			  AND TYPE = {$typeFolder}
			  AND REAL_OBJECT_ID <> ID
			ORDER BY ID ASC
		";

		$firstLevel = [];
		$realIds = [];
		foreach ($this->connection->query($sqlFirstLevel) as $row)
		{
			$id = (int)$row['ID'];
			$realId = (int)$row['REAL_OBJECT_ID'];
			$firstLevel[$id] = [
				'id' => $id,
				'name' => (string)$row['NAME'],
				'parentId' => (int)$row['PARENT_ID'],
				'realObjectId' => $realId,
				'createTime' => $this->coerceDateTime($row['CREATE_TIME']),
				'isReplica' => false,
			];
			$realIds[$realId] = true;
		}

		// Replica detection step 1: realObjectId exists as a real folder in the SAME storage.
		// Matches NewDiskStorage::isRealObjectExists() (which inspects $treeData built from
		// the user's own storage; the user's own storage is always loaded in buildSelfTree()).
		$existsInOwnStorage = [];
		if ($realIds)
		{
			$inList = implode(',', array_keys($realIds));
			// TYPE = folder mirrors baseline: isRealObjectExists() inspects $treeData, which
			// buildSelfTree() populates with folders only. Without it a symlink whose
			// REAL_OBJECT_ID collided with a file id in this storage would be misclassified as
			// a replica.
			$sqlExists = "
				SELECT ID FROM b_disk_object
				WHERE ID IN ({$inList})
				  AND STORAGE_ID = {$storageId}
				  AND DELETED_TYPE = {$deletedTypeNone}
				  AND TYPE = {$typeFolder}
				  {$this->excludedCodeFilter()}
			";
			foreach ($this->connection->query($sqlExists) as $row)
			{
				$existsInOwnStorage[(int)$row['ID']] = true;
			}
		}

		// Replica detection step 2: a foreign first-level target that is itself a descendant of
		// ANOTHER foreign first-level target (user connected both a foreign folder and its nested
		// subfolder). Baseline expands the ancestor link first, pulling the nested target into
		// $treeData, so the nested link then hits isRealObjectExists() and is marked a replica -
		// its subtree is enumerated once (under the ancestor), never twice. Without this check both
		// links enumerate the shared subtree. Routing (realToLink) is intentionally left intact:
		// baseline setLink() runs over the link regardless of the replica flag, so only enumeration
		// is gated. Own-storage nesting is already covered by $existsInOwnStorage above; the nested
		// (discovered) level has its own equivalent via $reachableUnderRouted.
		//
		// Determinism note: baseline decides the ancestor-vs-nested order by buildSelfTree() row
		// order (no ORDER BY), so for the reverse topology (nested link scanned BEFORE its ancestor)
		// stock can leave BOTH as non-replicas and enumerate the shared subtree twice - an order-
		// dependent, non-deterministic outcome. The closure-table check below marks the nested
		// target a replica regardless of scan order, deliberately NORMALISING that non-determinism
		// to the single-enumeration outcome. This is a conscious tightening, not a regression: the
		// composition never gains items over baseline, it only stops depending on DB row order.
		$reachableUnderFirstLevel = [];
		$foreignRealIds = array_diff(array_keys($realIds), array_keys($existsInOwnStorage));
		if (count($foreignRealIds) > 1)
		{
			$foreignList = implode(',', array_map('intval', $foreignRealIds));
			$sqlNestedFirstLevel = "
				SELECT DISTINCT OBJECT_ID
				FROM b_disk_object_path
				WHERE OBJECT_ID IN ({$foreignList})
				  AND PARENT_ID IN ({$foreignList})
				  AND DEPTH_LEVEL > 0
			";
			foreach ($this->connection->query($sqlNestedFirstLevel) as $row)
			{
				$reachableUnderFirstLevel[(int)$row['OBJECT_ID']] = true;
			}
		}

		// Mirror NewDiskStorage::buildTree() setLink-overwrite semantics: later first-level
		// symlinks pointing at the same realObjectId override the path-routing target. This
		// is "last wins" - matches getSymlinkFoldersSortedById()'s ascending iteration over
		// $treeData, where the second setLink() call in buildTree() overwrites the first.
		// isReplica is independent of routing - it only controls whether snapshotFromLinks
		// enumerates the symlink's subtree (replicas are skipped to avoid duplicate items).
		$seenReal = [];
		foreach ($firstLevel as $id => $link)
		{
			$rid = $link['realObjectId'];
			$realInOwnStorage = isset($existsInOwnStorage[$rid]);
			$isReplica = $realInOwnStorage
				|| isset($seenReal[$rid])
				|| isset($reachableUnderFirstLevel[$rid]);
			$link['isReplica'] = $isReplica;
			$this->symlinks[$id] = $link;

			// Route the real object to this symlink ONLY when baseline would have done so.
			// In baseline, buildSelfTree() adds a link to $firstLevelLinks (the array that later
			// drives setLink()) only when its real object is NOT already present in $treeData -
			// i.e. NOT a real folder in the user's own storage. A symlink duplicating an own-storage
			// real folder (existsInOwnStorage) is excluded there, so setLink() is never called and
			// the real object keeps its natural path. Duplicate symlinks to the SAME foreign target
			// are all first-level (their target isn't loaded yet at buildSelfTree time), so they DO
			// compete - "last wins" - matching baseline.
			if (!$realInOwnStorage)
			{
				// Append (not overwrite): duplicate first-level symlinks to the same foreign
				// target are all recorded so resolveRoutedLinkPath() can fall back to a valid
				// one when the highest-id winner has a null path. ID ASC scan
				// order means the list stays ascending; the resolver reads it highest-first.
				$this->realToLink[$rid][] = $id;
			}
			if (!$isReplica)
			{
				$seenReal[$rid] = $id;
			}
		}

		$this->discoverNestedSymlinks(array_keys($seenReal), $seenReal, $existsInOwnStorage);
	}

	/**
	 * Expand the set of symlinks by walking subtrees of already-known link targets.
	 * Mirrors NewDiskStorage::buildTreeRecursiveFromLinks() but without building full $treeData.
	 *
	 * @param int[] $pendingRealIds real-object-ids whose subtrees we still need to scan
	 * @param array<int,int> $seenReal map realObjectId => linkId (mutated)
	 * @param array<int,bool> $existsInOwnStorage realObjectIds that are real folders in own storage
	 */
	private function discoverNestedSymlinks(array $pendingRealIds, array &$seenReal, array $existsInOwnStorage): void
	{
		$deletedTypeNone = (int)ObjectTable::DELETED_TYPE_NONE;
		$typeFolder = (int)ObjectTable::TYPE_FOLDER;
		$rightSql = $this->securityContext->getSqlExpressionForList('o.ID', 'o.CREATED_BY');

		// No depth cap: the visited guard (isset($this->symlinks[$id]) below) processes each
		// symlink at most once, so the BFS is bounded by the finite number of symlink rows and
		// always terminates. Mirrors baseline buildTreeRecursiveFromLinks(), which recurses
		// unbounded and stops via isTreeNodeExists()/isRealObjectExists().
		while ($pendingRealIds)
		{
			$inList = implode(',', array_map('intval', $pendingRealIds));
			$pendingRealIds = [];

			// No explicit o.STORAGE_ID filter (unlike baseline buildTreeFromLink, which pins
			// object.STORAGE_ID to the single link target's storage). This query fans out over
			// MANY pending targets at once, each possibly in a different storage, so a single
			// storageId is not applicable. We rely on the structural invariant that a closure
			// chain never crosses a storage boundary: p.PARENT_ID IN (targets) only reaches
			// descendants that live in the same storage as their ancestor.
			$sql = "
				SELECT o.ID, o.NAME, o.PARENT_ID, o.REAL_OBJECT_ID, o.CREATE_TIME
				FROM b_disk_object o
				INNER JOIN b_disk_object_path p ON p.OBJECT_ID = o.ID
				WHERE p.PARENT_ID IN ({$inList})
				  AND p.DEPTH_LEVEL > 0
				  AND o.DELETED_TYPE = {$deletedTypeNone}
				  AND o.TYPE = {$typeFolder}
				  AND o.REAL_OBJECT_ID <> o.ID
				  AND ({$rightSql})
			";

			// Buffer this level's candidate symlinks so we can batch the replica check below.
			$candidates = [];
			$candidateRids = [];
			foreach ($this->connection->query($sql) as $row)
			{
				$id = (int)$row['ID'];
				if (isset($this->symlinks[$id]))
				{
					continue;
				}
				$rid = (int)$row['REAL_OBJECT_ID'];
				$candidates[] = [
					'id' => $id,
					'name' => (string)$row['NAME'],
					'parentId' => (int)$row['PARENT_ID'],
					'realObjectId' => $rid,
					'createTime' => $this->coerceDateTime($row['CREATE_TIME']),
				];
				$candidateRids[$rid] = true;
			}

			if (!$candidates)
			{
				continue;
			}

			// Replica parity with baseline isRealObjectExists() == isset($treeData[$rid]).
			// A nested symlink is a replica when its target is already reachable in the tree.
			// Three cases must be covered:
			//   * target is itself a routed link or an own-storage folder ($seenReal /
			//     $existsInOwnStorage, both first-level only);
			//   * target is a real folder in the user's OWN storage, computed over this level's
			//     candidates ($ownAtThisLevel), since $existsInOwnStorage is first-level only;
			//   * target sits as a plain descendant under an already-routed target
			//     ($reachableUnderRouted, via the closure table).
			$ownAtThisLevel = [];
			if ($candidateRids)
			{
				$ridListOwn = implode(',', array_map('intval', array_keys($candidateRids)));
				$sqlOwn = "
					SELECT ID FROM b_disk_object
					WHERE ID IN ({$ridListOwn})
					  AND STORAGE_ID = {$this->storageId}
					  AND DELETED_TYPE = {$deletedTypeNone}
					  AND TYPE = {$typeFolder}
					  {$this->excludedCodeFilter()}
				";
				foreach ($this->connection->query($sqlOwn) as $ownRow)
				{
					$ownAtThisLevel[(int)$ownRow['ID']] = true;
				}
			}

			$reachableUnderRouted = [];
			$routedRoots = array_keys($seenReal);
			if ($routedRoots)
			{
				$ridList = implode(',', array_map('intval', array_keys($candidateRids)));
				$rootList = implode(',', array_map('intval', $routedRoots));
				$sqlReach = "
					SELECT DISTINCT OBJECT_ID
					FROM b_disk_object_path
					WHERE OBJECT_ID IN ({$ridList})
					  AND PARENT_ID IN ({$rootList})
					  AND DEPTH_LEVEL > 0
				";
				foreach ($this->connection->query($sqlReach) as $reachRow)
				{
					$reachableUnderRouted[(int)$reachRow['OBJECT_ID']] = true;
				}
			}

			foreach ($candidates as $cand)
			{
				$id = $cand['id'];
				$rid = $cand['realObjectId'];
				$isReplica =
					isset($seenReal[$rid])
					|| isset($existsInOwnStorage[$rid])
					|| isset($ownAtThisLevel[$rid])
					|| isset($reachableUnderRouted[$rid]);

				$cand['isReplica'] = $isReplica;
				$this->symlinks[$id] = $cand;

				if (!$isReplica)
				{
					$seenReal[$rid] = $id;
					// At the nested level a duplicate of an already-routed target is marked
					// replica above, so at most one id is appended per rid here - but keep the
					// list form consistent with the first-level write.
					$this->realToLink[$rid][] = $id;
					$pendingRealIds[] = $rid;
				}
				// A nested replica (its real object is already reachable in the tree) is NOT
				// collected into baseline's subLinks/deepLinks/theDeepestLinks - buildTreeFromLink()
				// only returns nodes where !isRealObjectExists(). setLink() runs solely over those
				// arrays, so a replica NEVER routes its real object in baseline; we must not either.
			}
		}
	}

	private function coerceDateTime($value): ?DateTime
	{
		if ($value instanceof DateTime)
		{
			return $value;
		}
		if (is_string($value) && $value !== '')
		{
			try
			{
				return new DateTime($value, 'Y-m-d H:i:s');
			}
			catch (\Throwable $e)
			{
				return null;
			}
		}
		return null;
	}

	/**
	 * SQL fragment excluding EXCLUDED_CODES folders from an own-storage replica check.
	 *
	 * buildSelfTree() `continue`s over the "Uploaded files" folder, so it never enters
	 * baseline $treeData and isRealObjectExists() is false for a symlink targeting it -
	 * i.e. such a symlink is NOT a replica. The own-storage checks (existsInOwnStorage,
	 * ownAtThisLevel) would otherwise find that folder and wrongly mark the symlink a
	 * replica. NULL-safe: a folder with no CODE must still pass.
	 *
	 * @param string $column CODE column reference for the target query (unqualified here)
	 */
	private function excludedCodeFilter(string $column = 'CODE'): string
	{
		$helper = $this->connection->getSqlHelper();
		$quoted = array_map(static fn(string $c): string => "'" . $helper->forSql($c) . "'", self::EXCLUDED_CODES);

		return "AND ({$column} IS NULL OR {$column} NOT IN (" . implode(',', $quoted) . '))';
	}
}
