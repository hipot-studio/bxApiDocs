<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Disk\Service\Task;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Uf\FileUserType;
use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Entity\DiskFile;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Repository\DiskFileRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Service\UpdateTaskService;

class CopyFileService
{
	public const DESCRIPTION_FILE_ID_PATTERN = '/\[disk file id=(' . FileUserType::NEW_FILE_PREFIX . '?\d+)([^]]*)]/i';

	public function __construct(
		private readonly TaskRepositoryInterface $taskRepository,
		private readonly DiskFileRepositoryInterface $fileRepository,
		private readonly UpdateTaskService $updateService,
	)
	{
	}

	public function copyAttachments(string $description, int $userId, array $fileIds): array
	{
		if (empty($fileIds) || !Loader::includeModule('disk'))
		{
			return [$fileIds, $description];
		}

		[$attachedFileIds, $diskFileIds] = $this->separateFileIdsByType($fileIds);

		$clonedIdMap = $this->cloneAttachedFiles($attachedFileIds, $userId);

		if (empty($diskFileIds) && empty($clonedIdMap))
		{
			return [$fileIds, $description];
		}

		$finalFileIds = $this->buildAttachmentList($diskFileIds, $clonedIdMap);

		$oldToNewFileIdMap = $this->buildOldToNewIdMap($diskFileIds, $clonedIdMap, $attachedFileIds);
		$updatedDescription = $this->replaceFileIdsInDescription($description, $oldToNewFileIdMap);

		return [$finalFileIds, $updatedDescription];
	}

	private function separateFileIdsByType(array $fileIds): array
	{
		$attachedFileIds = [];
		$diskFileIds = [];

		foreach ($fileIds as $fileId)
		{
			$fileId = (string)$fileId;

			if ($this->isDiskFileId($fileId))
			{
				$diskFileIds[] = $fileId;
			}
			else
			{
				$attachedFileIds[] = $fileId;
			}
		}

		return [$attachedFileIds, $diskFileIds];
	}

	private function cloneAttachedFiles(array $attachedFileIds, int $userId): array
	{
		if (empty($attachedFileIds))
		{
			return [];
		}

		$userFieldManager = Driver::getInstance()->getUserFieldManager();

		return $userFieldManager->cloneUfValuesFromAttachedObject($attachedFileIds, $userId);
	}

	private function buildAttachmentList(array $diskFileIds, array $clonedIdMap): array
	{
		$clonedIds = array_values($clonedIdMap);

		$finalFileIds = array_merge($diskFileIds, $clonedIds);

		return array_values(array_unique($finalFileIds));
	}

	private function buildOldToNewIdMap(array $diskFileIds, array $clonedIdMap, array $attachedFileIds): array
	{
		$oldToNewIdMap = array_combine($diskFileIds, $diskFileIds) ?: [];

		$oldToNewIdMap += $clonedIdMap;

		$sourceDiskFiles = $this->fileRepository->getByIds($attachedFileIds);

		/** @var DiskFile $sourceDiskFile */
		foreach ($sourceDiskFiles as $sourceDiskFile)
		{
			$oldAttachedId = $sourceDiskFile->getId();

			if (!isset($clonedIdMap[$oldAttachedId]))
			{
				continue;
			}

			$oldDiskId = FileUserType::NEW_FILE_PREFIX . $sourceDiskFile->getDiskObjectId();
			$newAttachedId = $clonedIdMap[$oldAttachedId];

			$oldToNewIdMap[$oldDiskId] = $newAttachedId;
		}

		return $oldToNewIdMap;
	}

	private function replaceFileIdsInDescription(string $description, array $oldToNewFileIdMap): string
	{
		if (empty($oldToNewFileIdMap))
		{
			return $description;
		}

		return preg_replace_callback(
			self::DESCRIPTION_FILE_ID_PATTERN,
			static function (array $matches) use ($oldToNewFileIdMap): string
			{
				$oldId = $matches[1];

				$additionalProperties = $matches[2];

				if (isset($oldToNewFileIdMap[$oldId]))
				{
					$newFileId = $oldToNewFileIdMap[$oldId];

					return "[disk file id={$newFileId}{$additionalProperties}]";
				}

				return $matches[0];
			},
			$description,
		);
	}

	private function isDiskFileId(string $fileId): bool
	{
		return str_starts_with($fileId, FileUserType::NEW_FILE_PREFIX);
	}
}
