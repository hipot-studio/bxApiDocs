<?php

namespace Bitrix\StaffTrack\Internal\Integration\Disk;

use Bitrix\Disk\File;
use Bitrix\Main\Loader;
use Bitrix\StaffTrack\Internal\Model\CheckInTable;

class FileResolver
{
	private FileMapper $mapper;

	public function __construct()
	{
		$this->mapper = new FileMapper();
	}

	/**
	 * @param int $checkInId
	 * @param array $fileIds Disk file IDs
	 * @param int $currentUserId
	 * @return array
	 */
	public function resolve(int $checkInId, array $fileIds, int $currentUserId): array
	{
		if (empty($fileIds) || !Loader::includeModule('disk'))
		{
			return [];
		}

		$intFileIds = self::sanitizeIds($fileIds);

		$diskFilesById = self::loadDiskFiles($intFileIds);

		return $this->processFiles($checkInId, $intFileIds, $diskFilesById, $currentUserId);
	}

	/**
	 * @param array $checkInsData
	 * @param int $currentUserId
	 * @return array<int, array>
	 */
	public function resolveMany(array $checkInsData, int $currentUserId): array
	{
		if (!Loader::includeModule('disk'))
		{
			return [];
		}

		$allFileIds = [];
		$sanitizedMap = [];
		foreach ($checkInsData as $entry)
		{
			$checkInId = (int)$entry['id'];
			$intFileIds = self::sanitizeIds($entry['fileIds']);
			$sanitizedMap[$checkInId] = $intFileIds;
			array_push($allFileIds, ...$intFileIds);
		}

		$allFileIds = array_values(array_unique($allFileIds));
		if (empty($allFileIds))
		{
			return [];
		}

		$diskFilesById = self::loadDiskFiles($allFileIds);

		$result = [];
		foreach ($sanitizedMap as $checkInId => $intFileIds)
		{
			$result[$checkInId] = $this->processFiles($checkInId, $intFileIds, $diskFilesById, $currentUserId);
		}

		return $result;
	}

	/**
	 * @param array<int, File> $diskFilesById
	 */
	private function processFiles(int $checkInId, array $intFileIds, array $diskFilesById, int $currentUserId): array
	{
		$files = [];
		$validFileIds = [];
		$hasStale = false;

		foreach ($intFileIds as $fileId)
		{
			$diskFile = $diskFilesById[$fileId] ?? null;

			if (!self::isAccessible($diskFile, $currentUserId))
			{
				if (self::isStale($diskFile))
				{
					$hasStale = true;
				}
				else
				{
					$validFileIds[] = $fileId;
				}

				continue;
			}

			$validFileIds[] = $fileId;
			$files[] = $this->mapper->toArray($diskFile);
		}

		if ($hasStale)
		{
			self::syncFileIds($checkInId, $validFileIds);
		}

		return $files;
	}

	/**
	 * @return int[]
	 */
	private static function sanitizeIds(array $fileIds): array
	{
		return array_filter(
			array_map('intval', $fileIds),
			static fn(int $id) => $id > 0,
		);
	}

	/**
	 * @return array<int, File>
	 */
	private static function loadDiskFiles(array $ids): array
	{
		/** @var File[] $diskFiles */
		$diskFiles = File::loadBatchById($ids);

		$indexed = [];
		foreach ($diskFiles as $diskFile)
		{
			$indexed[$diskFile->getId()] = $diskFile;
		}

		return $indexed;
	}

	private static function isAccessible(?File $diskFile, int $userId): bool
	{
		if ($diskFile === null || $diskFile->isDeleted())
		{
			return false;
		}

		$storage = $diskFile->getStorage();
		if ($storage === null)
		{
			return false;
		}

		$securityContext = $storage->getSecurityContext($userId);

		return $diskFile->canRead($securityContext);
	}

	private static function isStale(?File $diskFile): bool
	{
		return $diskFile === null
			|| $diskFile->getStorage() === null;
	}

	private static function syncFileIds(int $checkInId, array $validFileIds): void
	{
		if ($checkInId <= 0)
		{
			return;
		}

		CheckInTable::update($checkInId, [
			'FILE_IDS' => $validFileIds,
		]);
	}
}