<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Disk\Service\Task;

use Bitrix\Disk\Driver;
use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Service\FileBbCodeService;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Service\FileIdService;

class CopyFileService
{
	public function __construct(
		private readonly FileIdService $fileIdService,
		private readonly FileBbCodeService $fileBbCodeService,
	)
	{
	}

	public function copyAttachments(string $description, int $userId, array $fileIds): array
	{
		if (empty($fileIds) || !Loader::includeModule('disk'))
		{
			return [$fileIds, $description];
		}

		[$attachedFileIds, $diskFileIds] = $this->fileIdService->separateIdsByType($fileIds);

		$clonedIdMap = $this->cloneAttachedFiles($attachedFileIds, $userId);

		if (empty($diskFileIds) && empty($clonedIdMap))
		{
			return [$fileIds, $description];
		}

		$finalFileIds = $this->buildAttachmentList($diskFileIds, $clonedIdMap);

		$oldToNewFileIdMap = $this->buildOldToNewIdMap($diskFileIds, $clonedIdMap, $attachedFileIds);
		$updatedDescription = $this->fileBbCodeService->replaceIds($description, $oldToNewFileIdMap);

		return [$finalFileIds, $updatedDescription];
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

		foreach ($this->fileIdService->buildAttachedIdToDiskIdMap($attachedFileIds) as $oldAttachedId => $oldDiskId)
		{
			if (!isset($clonedIdMap[$oldAttachedId]))
			{
				continue;
			}

			$newAttachedId = $clonedIdMap[$oldAttachedId];

			$oldToNewIdMap[$oldDiskId] = $newAttachedId;
		}

		return $oldToNewIdMap;
	}
}
