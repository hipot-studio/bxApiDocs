<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Disk\Service;

use Bitrix\Disk\Uf\FileUserType;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Repository\DiskFileRepositoryInterface;

class FileIdService
{
	public function __construct(
		private readonly DiskFileRepositoryInterface $diskFileRepository,
	)
	{
	}

	public function separateIdsByType(array $fileIds): array
	{
		$attachedFileIds = [];
		$diskFileIds = [];

		foreach ($fileIds as $fileId)
		{
			if ($this->isDiskId($fileId))
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

	public function expandWithDiskIds(array $fileIds): array
	{
		$expandedMap = [];
		$attachedFileIds = [];

		foreach ($fileIds as $fileId)
		{
			$expandedMap[$fileId] = true;

			if (!$this->isDiskId($fileId))
			{
				$attachedFileIds[] = $fileId;
			}
		}

		foreach ($this->buildAttachedIdToDiskIdMap($attachedFileIds) as $diskFileId)
		{
			$expandedMap[$diskFileId] = true;
		}

		return array_keys($expandedMap);
	}

	public function buildAttachedIdToDiskIdMap(array $attachedFileIds): array
	{
		if (empty($attachedFileIds))
		{
			return [];
		}

		$map = [];

		$diskFiles = $this->diskFileRepository->getByIds($attachedFileIds);

		foreach ($diskFiles as $diskFile)
		{
			$diskObjectId = $diskFile->getDiskObjectId();
			if ($diskObjectId === null)
			{
				continue;
			}

			$map[(string)$diskFile->getId()] = FileUserType::NEW_FILE_PREFIX . $diskObjectId;
		}

		return $map;
	}

	private function isDiskId(int|string $fileId): bool
	{
		return is_string($fileId) && str_starts_with($fileId, FileUserType::NEW_FILE_PREFIX);
	}
}
