<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UploadCleanup;

use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\File;
use Bitrix\Disk\Integration\ImManager;
use Bitrix\Disk\Internals\AttachedObjectTable;
use Bitrix\Disk\Internals\EditSessionTable;
use Bitrix\Disk\Internals\ExternalLinkTable;
use Bitrix\Disk\Internals\ObjectLockTable;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Internals\RightTable;
use Bitrix\Disk\Internals\SharingTable;
use Bitrix\Disk\Internals\TrackedObjectTable;
use Bitrix\Disk\ObjectLock;

final class CleanupGuard
{
	public const ATTACHED = 'attached';
	public const VERSIONED = 'versioned';
	public const MOVED = 'moved';
	public const IN_USE = 'in_use';

	public function check(
		File $file,
		int $currentUserId,
		int $expectedParentId,
		?int $expectedGlobalContentVersion = null,
	): ?string
	{
		$fileId = (int)$file->getId();

		return $this->checkBatch(
			[$fileId => $file],
			$currentUserId,
			[$fileId => $expectedParentId],
			$expectedGlobalContentVersion === null ? [] : [$fileId => $expectedGlobalContentVersion],
		)[$fileId] ?? null;
	}

	/**
	 * @param array<int, File> $filesById
	 * @param array<int, int> $expectedParentIds
	 * @param array<int, int> $expectedGlobalContentVersions
	 * @return array<int, string|null>
	 */
	public function checkBatch(
		array $filesById,
		int $currentUserId,
		array $expectedParentIds,
		array $expectedGlobalContentVersions = [],
	): array
	{
		return $this->checkBatchForUsers(
			$filesById,
			array_fill_keys(array_keys($filesById), $currentUserId),
			$expectedParentIds,
			$expectedGlobalContentVersions,
		);
	}

	/**
	 * @param array<int, File> $filesById
	 * @param array<int, int> $currentUserIdsByObjectId
	 * @param array<int, int> $expectedParentIds
	 * @param array<int, int> $expectedGlobalContentVersions
	 * @return array<int, string|null>
	 */
	public function checkBatchForUsers(
		array $filesById,
		array $currentUserIdsByObjectId,
		array $expectedParentIds,
		array $expectedGlobalContentVersions = [],
	): array
	{
		$filesById = array_filter(
			$filesById,
			static fn(File $file, int $objectId): bool => $objectId > 0 && (int)$file->getId() === $objectId,
			ARRAY_FILTER_USE_BOTH,
		);
		$objectIds = array_keys($filesById);
		if ($objectIds === [])
		{
			return [];
		}

		$attached = $this->existingIds(AttachedObjectTable::class, 'OBJECT_ID', $objectIds);
		$externalLinks = $this->existingIds(ExternalLinkTable::class, 'OBJECT_ID', $objectIds);
		$sharings = $this->existingIds(SharingTable::class, 'REAL_OBJECT_ID', $objectIds);
		$editSessions = $this->existingIds(EditSessionTable::class, 'OBJECT_ID', $objectIds);
		$documentSessions = $this->existingIds(DocumentSessionTable::class, 'OBJECT_ID', $objectIds);
		$trackedObjects = $this->existingIds(TrackedObjectTable::class, 'REAL_OBJECT_ID', $objectIds);
		$rights = $this->existingIds(RightTable::class, 'OBJECT_ID', $objectIds);
		$fileLinks = $this->fileLinkIds($objectIds);
		$foreignLocks = $this->foreignExclusiveLockIds($objectIds, $currentUserIdsByObjectId);
		$published = $this->publishedFileIds($objectIds);

		$result = [];
		foreach ($filesById as $objectId => $file)
		{
			if (isset($attached[$objectId]))
			{
				$result[$objectId] = self::ATTACHED;
			}
			elseif (
				(int)$file->getGlobalContentVersion()
				!== (int)($expectedGlobalContentVersions[$objectId] ?? 1)
			)
			{
				$result[$objectId] = self::VERSIONED;
			}
			elseif (
				(int)$file->getParentId() <= 0
				|| (int)$file->getParentId() !== (int)($expectedParentIds[$objectId] ?? 0)
			)
			{
				$result[$objectId] = self::MOVED;
			}
			elseif (
				isset($externalLinks[$objectId])
				|| isset($sharings[$objectId])
				|| isset($fileLinks[$objectId])
				|| isset($editSessions[$objectId])
				|| isset($documentSessions[$objectId])
				|| isset($trackedObjects[$objectId])
				|| isset($rights[$objectId])
				|| isset($foreignLocks[$objectId])
				|| isset($published[$objectId])
			)
			{
				$result[$objectId] = self::IN_USE;
			}
			else
			{
				$result[$objectId] = null;
			}
		}

		return $result;
	}

	/** @return array<int, true> */
	private function existingIds(string $tableClass, string $field, array $objectIds): array
	{
		$query = $tableClass::query()
			->setSelect([$field])
			->whereIn($field, $objectIds)
			->setDistinct()
			->exec()
		;
		$result = [];
		while ($row = $query->fetch())
		{
			$result[(int)$row[$field]] = true;
		}

		return $result;
	}

	/** @return array<int, true> */
	private function fileLinkIds(array $objectIds): array
	{
		$query = ObjectTable::query()
			->setSelect(['REAL_OBJECT_ID'])
			->whereIn('REAL_OBJECT_ID', $objectIds)
			->whereColumn('ID', '!=', 'REAL_OBJECT_ID')
			->setDistinct()
			->exec()
		;
		$result = [];
		while ($row = $query->fetch())
		{
			$result[(int)$row['REAL_OBJECT_ID']] = true;
		}

		return $result;
	}

	/** @return array<int, true> */
	private function foreignExclusiveLockIds(array $objectIds, array $currentUserIdsByObjectId): array
	{
		$rows = ObjectLockTable::getList([
			'select' => ['ID', 'TOKEN', 'OBJECT_ID', 'CREATED_BY', 'CREATE_TIME', 'EXPIRY_TIME', 'TYPE', 'IS_EXCLUSIVE'],
			'filter' => ['@OBJECT_ID' => $objectIds],
		])->fetchAll();
		$result = [];
		foreach ($rows as $row)
		{
			$objectId = (int)$row['OBJECT_ID'];
			$lock = ObjectLock::buildFromArray($row);
			if (
				$lock->isExclusive()
				&& !$lock->shouldProcessAutoUnlock()
				&& (
					!array_key_exists($objectId, $currentUserIdsByObjectId)
					|| !$lock->canUnlock((int)$currentUserIdsByObjectId[$objectId])
				)
			)
			{
				$result[$objectId] = true;
			}
		}

		return $result;
	}

	/** @return array<int, true> */
	private function publishedFileIds(array $objectIds): array
	{
		return (new ImManager())->getFileIdsPublishedInChats($objectIds);
	}
}
