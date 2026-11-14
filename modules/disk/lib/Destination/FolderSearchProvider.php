<?php

declare(strict_types=1);

namespace Bitrix\Disk\Destination;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internals\ObjectPathTable;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Search\SearchFilterBuilder;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Disk\Storage;
use Bitrix\Main\Type\Collection;

/**
 * Finds folders of a disk by name anywhere under its root and answers with the rows of a level, every one
 * of them carrying the path it was found at.
 *
 * A row is the row of @see FolderListProvider plus that path: the client draws a found folder with the very
 * same row. Their verdicts come from the same resolver, so a folder found by a search answers exactly what
 * the same folder answers inside its own level.
 */
class FolderSearchProvider
{
	/**
	 * The answer has no pages: a person narrows the query instead of walking a list, so everything past the
	 * limit is cut off. The limit counts the rows a person really sees - the tree is read on until that many
	 * folders survive the check of the rights - and it is the page the search over sub-folders of the module
	 * already answers with (@see \Bitrix\Disk\Controller\DataProviders\ChildrenDataProvider). The limit
	 * belongs to this provider alone: a level is the children of one folder and needs no limit at all.
	 */
	public const MAX_ITEMS = 50;

	/**
	 * The tree is read by pages holding a surplus over the limit: the rows a person may not read leave the
	 * answer once the page has arrived, and a query the first page already fills costs exactly one read.
	 */
	public const PAGE_SIZE = self::MAX_ITEMS * 2;

	/**
	 * The walk of the tree is held by the rows it reads, not by the pages it takes them in: a disk where
	 * almost nothing is readable would otherwise be walked to its very end only to answer with the handful of
	 * rows it holds anyway. Rows are what that walk costs the server, and counting them leaves the ceiling
	 * far enough above the limit for a folder buried under hundreds of unreadable namesakes to still be
	 * found. A query the first page fills pays nothing for it: the walk ends with that page.
	 */
	public const MAX_SCANNED_ROWS = 1000;

	private readonly VerdictResolver $verdictResolver;

	public function __construct(
		private readonly int $userId,
		?VerdictResolver $verdictResolver = null,
	)
	{
		$this->verdictResolver = $verdictResolver ?? new VerdictResolver($userId);
	}

	/**
	 * @return array<int, array>
	 */
	public function getFolders(
		BaseObject $object,
		Folder $rootFolder,
		DestinationOperation $operation,
		string $query,
	): array
	{
		$storage = $rootFolder->getStorage();
		$searchFilter = $this->buildSearchFilter($query);
		if (!$storage instanceof Storage || empty($searchFilter))
		{
			return [];
		}

		$securityContext = $this->getSecurityContext($storage);
		$folders = $this->getReadableFolders($rootFolder, $securityContext, $searchFilter);
		if (empty($folders))
		{
			return [];
		}

		$ancestorIdsByDepth = $this->loadAncestorIdsByDepth(
			array_map(static fn(Folder $folder): int => (int)$folder->getId(), $folders),
		);
		$paths = $this->resolvePaths($rootFolder, $ancestorIdsByDepth, $securityContext);
		//the rows of an answer share no parent, so the prefetch of a level does not fit them: the resolver is
		//given the ancestors the paths above are built of and weighs the whole answer at once
		$resolver = $this->verdictResolver->forFoundFolders(
			$object,
			$folders,
			$this->toAncestorIds($ancestorIdsByDepth),
		);

		$items = [];
		foreach ($folders as $folder)
		{
			$folderId = (int)$folder->getId();
			$items[] = [
				'id' => $folderId,
				'name' => (string)$folder->getName(),
				'hasSubFolders' => $folder->hasSubFolders(),
				'isLink' => $folder->isLink(),
				'verdict' => $resolver->resolveVerdict($object, $folder, $operation)->toArray(),
				'path' => $paths[$folderId] ?? [],
			];
		}

		Collection::sortByColumn($items, 'name');

		return $items;
	}

	/**
	 * The same filter the module searches objects of a folder with: a name the query opens, or the search
	 * index of the portal while it is ready. An empty query builds no filter at all and matches nothing.
	 */
	protected function buildSearchFilter(string $query): array
	{
		return (new SearchFilterBuilder())->buildFilter($query);
	}

	/**
	 * The folders of the tree the user may read, up to the limit of the answer. The limit belongs to the
	 * rows that survive canRead(), so the tree is asked for the next page while the limit is not filled, the
	 * page before it was whole and the ceiling of the walk is not reached: an answer shorter than the limit
	 * means the rows read hold no more readable matches.
	 *
	 * Pages are cut by an offset over a tree that lives on: a folder leaving it between one page and the
	 * next moves the border of the pages, and the same folder is free to arrive twice. A folder found twice
	 * is one row of the answer, so the folders are held by their ids.
	 *
	 * @return Folder[]
	 */
	protected function getReadableFolders(
		Folder $rootFolder,
		SecurityContext $securityContext,
		array $searchFilter,
	): array
	{
		$readableFolders = [];
		$scannedRows = 0;
		while ($scannedRows < self::MAX_SCANNED_ROWS)
		{
			$rows = $this->loadMatchingRows($rootFolder, $searchFilter, $scannedRows);
			if (empty($rows))
			{
				break;
			}

			$scannedRows += count($rows);

			$folders = array_values(array_filter(
				$rows,
				static fn(BaseObject $row): bool => $row instanceof Folder,
			));

			$this->preloadRights($securityContext, $folders);
			foreach ($folders as $folder)
			{
				if (!$folder->canRead($securityContext))
				{
					continue;
				}

				$readableFolders[(int)$folder->getId()] = $folder;
				if (count($readableFolders) >= self::MAX_ITEMS)
				{
					return array_values($readableFolders);
				}
			}

			//a page the tree could not fill is the last one it has, and the count is the one the tree
			//answered with: the rows dropped here are no word of the database about the pages left
			if (count($rows) < self::PAGE_SIZE)
			{
				break;
			}
		}

		return array_values($readableFolders);
	}

	/**
	 * One page of the candidates, read with the fake context the very way a level is read
	 * (@see FolderListProvider::loadChildFolders). The SQL filter of rights leans on b_disk_simple_right,
	 * which a background job fills at the end of the request and never reconciles with b_disk_right, so a
	 * stale row there would hide from a search the folder the same person opens in its own level. The tree
	 * answers with the candidates the name narrows it to and canRead() goes over b_disk_right itself, the
	 * source of truth of the check, the single place a search and a level both decide by.
	 *
	 * The order is total: pages are cut by an offset, and namesake folders - a disk holds plenty of them -
	 * would otherwise be free to change places between one page and the next.
	 *
	 * The rows are answered as the tree gave them, the type left unweighed: how many of them arrived is the
	 * word of the database about the pages left, and a row this provider drops takes nothing away from it.
	 *
	 * @return BaseObject[] one page of the rows of the tree
	 */
	protected function loadMatchingRows(Folder $rootFolder, array $searchFilter, int $offset): array
	{
		$descendants = $rootFolder->getDescendants(
			Driver::getInstance()->getFakeSecurityContext(),
			[
				'select' => ['*', 'HAS_SUBFOLDERS'],
				'filter' => array_merge(['TYPE' => ObjectTable::TYPE_FOLDER], $searchFilter),
				'order' => ['NAME' => 'ASC', 'ID' => 'ASC'],
				'limit' => self::PAGE_SIZE,
				'offset' => $offset,
				//the subtree of a search is the whole disk, and there the name narrows the candidates by an
				//index of the object table before the tree is asked about any of them
				'subtree_check' => ObjectTable::SUBTREE_CHECK_EXISTS,
			],
			//the answer is ordered by name, and a sort by depth of the subtree would undo it
			orderDepthLevel: null,
		);

		return array_values($descendants);
	}

	/**
	 * Rights of a whole page in one query. The rows of a search share no parent, so the level preload of
	 * FolderListProvider does not fit them: they are asked for by their own ids, the folder behind a link
	 * among them. A context that keeps no rights of its own has nothing to prefetch.
	 *
	 * @param Folder[] $folders
	 */
	protected function preloadRights(SecurityContext $securityContext, array $folders): void
	{
		if ($securityContext instanceof DiskSecurityContext)
		{
			$securityContext->preloadOperationsForObjects($folders);
		}
	}

	/**
	 * Rights of the ancestors of every path of the answer in one go, the way a page of the rows is weighed
	 * (@see preloadRights). A context that keeps no rights of its own has nothing to prefetch.
	 *
	 * @param int[] $objectIds
	 * @return int[] those of them the user may read
	 */
	protected function filterReadable(SecurityContext $securityContext, array $objectIds): array
	{
		if ($securityContext instanceof DiskSecurityContext)
		{
			$securityContext->preloadOperationsForObjectIds($objectIds);
		}

		return array_values(array_filter(
			$objectIds,
			static fn(int $objectId): bool => $securityContext->canRead($objectId),
		));
	}

	/**
	 * @param int[] $folderIds
	 * @return array<int, array<int, int>> id of the ancestor by its distance to the folder, by folder id
	 */
	protected function loadAncestorIdsByDepth(array $folderIds): array
	{
		if (empty($folderIds))
		{
			return [];
		}

		$rows = ObjectPathTable::query()
			->setSelect(['OBJECT_ID', 'PARENT_ID', 'DEPTH_LEVEL'])
			->whereIn('OBJECT_ID', $folderIds)
			->exec()
		;

		$ancestorIds = [];
		while ($row = $rows->fetch())
		{
			$ancestorIds[(int)$row['OBJECT_ID']][(int)$row['DEPTH_LEVEL']] = (int)$row['PARENT_ID'];
		}

		return $ancestorIds;
	}

	/**
	 * @param int[] $folderIds
	 * @return array<int, string> name by id
	 */
	protected function loadNames(array $folderIds): array
	{
		if (empty($folderIds))
		{
			return [];
		}

		$rows = ObjectTable::query()
			->setSelect(['ID', 'NAME'])
			->whereIn('ID', $folderIds)
			->exec()
		;

		$names = [];
		while ($row = $rows->fetch())
		{
			$names[(int)$row['ID']] = (string)$row['NAME'];
		}

		return $names;
	}

	protected function getSecurityContext(Storage $storage): SecurityContext
	{
		return $storage->getSecurityContext($this->userId);
	}

	/**
	 * The ancestors the path is built of are the rows of the tree, a link of the answer included: the path
	 * tells where the row was found, while the verdict of that row speaks about the folder it points at.
	 *
	 * @param array<int, array<int, int>> $ancestorIdsByDepth
	 * @return array<int, array<int, array{id: int, name: string}>> path by the id of the folder
	 */
	private function resolvePaths(
		Folder $rootFolder,
		array $ancestorIdsByDepth,
		SecurityContext $securityContext,
	): array
	{
		$ancestorIds = array_values(array_unique(array_merge(...$ancestorIdsByDepth)));
		$names = $this->loadNames($this->filterReadable($securityContext, $ancestorIds));

		$paths = [];
		foreach ($ancestorIdsByDepth as $folderId => $idsByDepth)
		{
			$paths[$folderId] = $this->buildPath((int)$rootFolder->getId(), $idsByDepth, $names);
		}

		return $paths;
	}

	/**
	 * The path starts at the root of the disk the search was run over: a folder above that root belongs to
	 * another level of the tree and says nothing about where inside the disk the folder was found. The last
	 * segment is the found folder itself, which is the place the client shows once the row is chosen.
	 *
	 * @param array<int, int> $idsByDepth id of the ancestor by its distance to the folder, zero being itself
	 * @param array<int, string> $names names of the ancestors the user may read, by their ids
	 * @return array<int, array{id: int, name: string}>
	 */
	private function buildPath(int $rootId, array $idsByDepth, array $names): array
	{
		$rootDepth = array_search($rootId, $idsByDepth, true);
		if ($rootDepth === false)
		{
			return [];
		}

		$path = [];
		for ($depth = $rootDepth; $depth >= 0; $depth--)
		{
			$ancestorId = $idsByDepth[$depth] ?? null;
			//a chain broken in the middle is no path at all: the client would draw a place that does not exist
			if ($ancestorId === null)
			{
				return [];
			}

			//an ancestor the user may not read names no place of the disk to them: the path is drawn without
			//it, the way the breadcrumbs of the file picker are
			//(@see \Bitrix\Disk\FilePicker\Provider::loadParentFolders)
			if (!isset($names[$ancestorId]))
			{
				continue;
			}

			$path[] = [
				'id' => $ancestorId,
				'name' => $names[$ancestorId],
			];
		}

		return $path;
	}

	/**
	 * The ancestors of a folder the way the resolver of the verdicts reads them: a folder is not one of its
	 * own ancestors (@see \Bitrix\Disk\Internals\ObjectPathTable::getAncestors), while the rows of the path
	 * the answer is drawn with start at the folder itself, at the distance of zero.
	 *
	 * @param array<int, array<int, int>> $ancestorIdsByDepth
	 * @return array<int, int[]> ancestors of a folder, itself left out, by the id of that folder
	 */
	private function toAncestorIds(array $ancestorIdsByDepth): array
	{
		$ancestorIds = [];
		foreach ($ancestorIdsByDepth as $folderId => $idsByDepth)
		{
			unset($idsByDepth[0]);
			$ancestorIds[$folderId] = array_values($idsByDepth);
		}

		return $ancestorIds;
	}
}
