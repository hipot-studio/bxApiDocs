<?php

namespace Bitrix\Disk\Internal\Service\Archive;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internal\Access\DownloadAccess;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Disk\ZipNginx\Archive;

class ArchiveDownloadAccessChecker
{
	private const BATCH_SIZE = 500;

	/**
	 * @param array<int, BaseObject> $objects
	 * @param callable(BaseObject): ?SecurityContext $securityContextResolver
	 */
	public function canDownloadObjects(
		array $objects,
		callable $securityContextResolver,
		?int $fileLimit = null,
		bool $shouldCheckDescendants = true,
		bool $shouldUseRightsCheck = true,
		bool $shouldFollowFolderLinks = false,
		bool $shouldPreloadSelectedObjects = true,
	): bool
	{
		$objectGroups = $this->groupObjectsBySecurityContext($objects, $securityContextResolver);
		if ($objectGroups === null)
		{
			return false;
		}

		$fileCount = 0;
		$fileLimit ??= Archive::getMaxFilesInArchive();

		foreach ($objectGroups as $objectGroup)
		{
			$securityContext = $objectGroup['securityContext'];
			$groupObjects = $objectGroup['objects'];

			if ($shouldPreloadSelectedObjects && $securityContext instanceof DiskSecurityContext)
			{
				$securityContext->preloadOperationsForObjects($groupObjects);
			}

			$folders = [];

			foreach ($groupObjects as $object)
			{
				if (!$object->canDownload($securityContext))
				{
					return false;
				}

				if ($object instanceof File)
				{
					$fileCount++;
					if ($fileCount > $fileLimit)
					{
						return false;
					}
				}

				if ($object instanceof Folder)
				{
					$folders[] = $object;
				}
			}

			if (
				$shouldCheckDescendants
				&& !$this->canDownloadFoldersDescendants(
					folders: $folders,
					securityContext: $securityContext,
					fileCount: $fileCount,
					fileLimit: $fileLimit,
					shouldUseRightsCheck: $shouldUseRightsCheck,
					shouldFollowFolderLinks: $shouldFollowFolderLinks,
				)
			)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<int, Folder> $folders
	 */
	public function canDownloadFoldersDescendants(
		array $folders,
		SecurityContext $securityContext,
		int &$fileCount = 0,
		?int $fileLimit = null,
		bool $shouldUseRightsCheck = true,
		bool $shouldFollowFolderLinks = false,
	): bool
	{
		$folderIds = $this->getFolderIds($folders, $shouldFollowFolderLinks);
		if (empty($folderIds))
		{
			return true;
		}

		$fileLimit ??= Archive::getMaxFilesInArchive();
		$folderIdMap = array_fill_keys($folderIds, true);
		$pendingFolderIds = $folderIds;
		$pendingFolderIdOffset = 0;
		$processedObjectIds = [];
		$batch = [];

		while ($pendingFolderIdOffset < count($pendingFolderIds))
		{
			$currentFolderIds = array_slice($pendingFolderIds, $pendingFolderIdOffset, self::BATCH_SIZE);
			$pendingFolderIdOffset += count($currentFolderIds);

			foreach ($this->getDescendantRows($currentFolderIds, $securityContext, $shouldUseRightsCheck) as $row)
			{
				$objectId = (int)($row['ID'] ?? 0);
				if ($objectId <= 0 || isset($folderIdMap[$objectId]) || isset($processedObjectIds[$objectId]))
				{
					continue;
				}

				$processedObjectIds[$objectId] = true;
				$objectType = (int)($row['TYPE'] ?? 0);

				if ($objectType === ObjectTable::TYPE_FILE)
				{
					$fileCount++;
					if ($fileCount > $fileLimit)
					{
						return false;
					}
				}

				if ($shouldFollowFolderLinks && $objectType === ObjectTable::TYPE_FOLDER)
				{
					$realObjectId = (int)($row['REAL_OBJECT_ID'] ?? 0);

					if ($realObjectId > 0 && $realObjectId !== $objectId && !isset($folderIdMap[$realObjectId]))
					{
						$folderIdMap[$realObjectId] = true;
						$pendingFolderIds[] = $realObjectId;
					}
				}

				$batch[] = $row;
				if (count($batch) >= $this->getBatchSize())
				{
					if (!$this->canReadRows($batch, $securityContext))
					{
						return false;
					}

					$batch = [];
				}
			}
		}

		return empty($batch) || $this->canReadRows($batch, $securityContext);
	}

	/**
	 * @param array<int, BaseObject> $objects
	 * @param callable(BaseObject): ?SecurityContext $securityContextResolver
	 * @return array<int, array{securityContext: SecurityContext, objects: array<int, BaseObject>}>|null
	 */
	private function groupObjectsBySecurityContext(array $objects, callable $securityContextResolver): ?array
	{
		$objectGroups = [];

		foreach ($objects as $object)
		{
			if (!$object instanceof BaseObject)
			{
				return null;
			}

			$securityContext = $securityContextResolver($object);
			if (!$securityContext instanceof SecurityContext)
			{
				return null;
			}

			$securityContextKey = DownloadAccess::getSecurityContextGroupKey($object, $securityContext);
			$objectGroups[$securityContextKey]['securityContext'] ??= $securityContext;
			$objectGroups[$securityContextKey]['objects'][] = $object;
		}

		return $objectGroups;
	}

	/**
	 * @param array<int, Folder> $folders
	 * @return array<int, int>
	 */
	private function getFolderIds(array $folders, bool $shouldFollowFolderLinks): array
	{
		$folderIds = [];

		foreach ($folders as $folder)
		{
			if (!$folder instanceof Folder)
			{
				continue;
			}

			$folderId = (int)(
				$shouldFollowFolderLinks
					? $folder->getRealObjectId()
					: $folder->getId()
			);
			if ($folderId > 0)
			{
				$folderIds[] = $folderId;
			}
		}

		return array_values(array_unique($folderIds));
	}

	/**
	 * @param array<int, int> $folderIds
	 * @return iterable<int, array<string, mixed>>
	 */
	protected function getDescendantRows(
		array $folderIds,
		SecurityContext $securityContext,
		bool $shouldUseRightsCheck,
	): iterable
	{
		foreach (array_chunk($folderIds, self::BATCH_SIZE) as $folderIdBatch)
		{
			$parameters = [
				'select' => ['ID', 'REAL_OBJECT_ID', 'TYPE', 'CREATED_BY'],
				'filter' => [
					'@PATH_CHILD.PARENT_ID' => $folderIdBatch,
					'DELETED_TYPE' => ObjectTable::DELETED_TYPE_NONE,
				],
			];

			if ($shouldUseRightsCheck)
			{
				$parameters
					= Driver::getInstance()
						->getRightsManager()
						->addRightsCheck(
							securityContext: $securityContext,
							parameters: $parameters,
							specificColumns: ['ID', 'CREATED_BY'],
						)
				;
			}

			$query = ObjectTable::getList($parameters);
			while ($row = $query->fetch())
			{
				yield $row;
			}
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function canReadRows(array $rows, SecurityContext $securityContext): bool
	{
		$objectIds = [];

		foreach ($rows as $row)
		{
			$objectId = (int)($row['ID'] ?? 0);
			if ($objectId > 0)
			{
				$objectIds[] = $objectId;
			}
		}

		$objectIds = array_values(array_unique($objectIds));
		if ($securityContext instanceof DiskSecurityContext)
		{
			$securityContext->preloadOperationsForObjectIds($objectIds);
		}

		foreach ($objectIds as $objectId)
		{
			if (!$securityContext->canRead($objectId))
			{
				return false;
			}
		}

		return true;
	}

	protected function getBatchSize(): int
	{
		return self::BATCH_SIZE;
	}
}
