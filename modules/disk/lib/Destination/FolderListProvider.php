<?php

declare(strict_types=1);

namespace Bitrix\Disk\Destination;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Disk\Storage;
use Bitrix\Main\Data\ManagedCache;
use Bitrix\Main\Type\Collection;

/**
 * Builds one level of the destination tree: sub-folders of a folder, each with a verdict.
 *
 * A folder unfit for the operation stays in the level and carries a denying verdict; only a folder
 * the user may not read is dropped. Rights of the whole level are preloaded in one query.
 */
class FolderListProvider
{
	private readonly VerdictResolver $verdictResolver;

	/**
	 * The cache of the portal is only what the resolver of the level is built out of, so a caller passing a
	 * resolver of its own has already made that choice and the cache goes unused.
	 */
	public function __construct(
		private readonly int $userId,
		ManagedCache $managedCache,
		?VerdictResolver $verdictResolver = null,
	)
	{
		//the levels of one popup weigh one and the same source, and a verdict of a level is a hint, not a permission
		$this->verdictResolver = $verdictResolver
			?? new VerdictResolver($userId, objectSizeCache: ObjectSizeCache::keepingAnswers($managedCache))
		;
	}

	/**
	 * @return array<int, array>
	 */
	public function getFolders(
		BaseObject $object,
		Folder $parentFolder,
		DestinationOperation $operation,
	): array
	{
		$folders = $this->getLevelFolders($parentFolder);
		$levelResolver = $this->verdictResolver->forLevel($object, $parentFolder, $folders);

		return $this->buildItems(
			$folders,
			static fn(Folder $folder): array => $levelResolver
				->resolveVerdict($object, $folder, $operation)
				->toArray(),
		);
	}

	/**
	 * The same level for a whole selection: every row carries the verdict of the selection, all or nothing.
	 *
	 * @return array<int, array>
	 */
	public function getSelectionFolders(
		ObjectSelection $selection,
		Folder $parentFolder,
		DestinationOperation $operation,
	): array
	{
		$folders = $this->getLevelFolders($parentFolder);
		$levelResolver = $this->verdictResolver->forSelectionLevel($selection, $parentFolder, $folders);

		return $this->buildItems(
			$folders,
			static fn(Folder $folder): array => $levelResolver
				->resolveSelectionVerdict($selection, $folder, $operation)
				->toSelectionArray(),
		);
	}

	/**
	 * @return Folder[]
	 */
	private function getLevelFolders(Folder $parentFolder): array
	{
		$storage = $parentFolder->getStorage();
		if (!$storage instanceof Storage)
		{
			return [];
		}

		$securityContext = $this->getSecurityContext($storage);
		$securityContext->preloadOperationsForChildren($parentFolder->getRealObjectId());

		return $this->getReadableFolders($parentFolder, $securityContext);
	}

	/**
	 * @param Folder[] $folders
	 * @param callable(Folder): array $buildVerdict
	 * @return array<int, array>
	 */
	private function buildItems(array $folders, callable $buildVerdict): array
	{
		$items = [];
		foreach ($folders as $folder)
		{
			$items[] = [
				'id' => (int)$folder->getId(),
				'name' => (string)$folder->getName(),
				'hasSubFolders' => $folder->hasSubFolders(),
				'isLink' => $folder->isLink(),
				'verdict' => $buildVerdict($folder),
			];
		}

		Collection::sortByColumn($items, 'name');

		return $items;
	}

	/**
	 * @return Folder[]
	 */
	protected function getReadableFolders(Folder $parentFolder, SecurityContext $securityContext): array
	{
		return array_values(array_filter(
			$this->loadChildFolders($parentFolder),
			static fn(Folder $folder): bool => $folder->canRead($securityContext),
		));
	}

	/**
	 * The level is read with the fake context and filtered by canRead() above: the SQL rights filter of
	 * getChildren() leans on b_disk_simple_right, which is filled by a background job at the end of the
	 * request and never reconciled with b_disk_right, so a stale row there would silently drop a folder the
	 * user may read. canRead() goes over b_disk_right itself, the source of truth of the access check.
	 *
	 * @return Folder[]
	 */
	protected function loadChildFolders(Folder $parentFolder): array
	{
		$children = $parentFolder->getChildren(Driver::getInstance()->getFakeSecurityContext(), [
			'select' => ['*', 'HAS_SUBFOLDERS'],
			'filter' => ['TYPE' => ObjectTable::TYPE_FOLDER],
		]);

		return array_values(array_filter(
			$children,
			static fn(BaseObject $child): bool => $child instanceof Folder,
		));
	}

	protected function getSecurityContext(Storage $storage): SecurityContext
	{
		return $storage->getSecurityContext($this->userId);
	}
}
