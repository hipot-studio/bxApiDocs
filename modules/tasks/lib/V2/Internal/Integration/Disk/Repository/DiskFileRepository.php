<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Disk\Repository;

use Bitrix\Disk\Type\AttachedObjectCollection;
use Bitrix\Disk\Uf\FileUserType;
use Bitrix\Disk\Uf\Integration\DiskUploaderController;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Entity\DiskFile;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Entity\DiskFileCollection;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Repository\Mapper\DiskFileMapper;
use Bitrix\Tasks\V2\Internal\Repository\TaskReadRepositoryInterface;

class DiskFileRepository implements DiskFileRepositoryInterface
{
	public function __construct(
		private readonly DiskFileMapper $diskFileMapper,
		private readonly TaskReadRepositoryInterface $taskReadRepository,
	)
	{

	}

	public function getByIds(array $ids): DiskFileCollection
	{
		if (!Loader::includeModule('disk'))
		{
			return new DiskFileCollection();
		}

		$files = DiskUploaderController::getFileInfo($ids);

		return $this->diskFileMapper->mapToCollection($files);
	}

	public function getObjectIdsByAttachmentIds(array $attachmentIds): array
	{
		if (!Loader::includeModule('disk'))
		{
			return [];
		}

		$attachments = $this->getAttachments($attachmentIds);

		if ($attachments === null)
		{
			return [];
		}

		$attachmentMap = [];
		foreach ($attachments as $attachment)
		{
			$attachmentMap[(int)$attachment->getObjectId()] = (int)$attachment->getId();
		}

		return $attachmentMap;
	}

	public function getOwnerIdsByFileIds(array $fileIds, int $taskId): array
	{
		if (!Loader::includeModule('disk'))
		{
			return [];
		}

		if (empty($fileIds))
		{
			return [];
		}

		$currentAttachments = $this->getCurrentTaskAttachments($taskId);

		$attachmentIds = [];

		foreach ($fileIds as $fileId)
		{
			[$type, $realValue] = FileUserType::detectType($fileId);
			if ($type === FileUserType::TYPE_NEW_OBJECT)
			{
				/** @var DiskFile $attachment */
				foreach ($currentAttachments as $attachment)
				{
					if ($attachment->customData['objectId'] === (int)$realValue)
					{
						$attachmentIds[$attachment->id] = $fileId;

						break;
					}
				}
			}
			else
			{
				$attachmentIds[$fileId] = $fileId;
			}
		}

		$attachments = $this->getAttachments(array_keys($attachmentIds));

		if ($attachments === null)
		{
			return [];
		}

		$attachmentMap = [];
		foreach ($attachments as $attachment)
		{
			$fileId = $attachmentIds[(int)$attachment->getId()] ?? null;

			if ($fileId !== null)
			{
				$attachmentMap[$fileId] = (int)$attachment->getCreateUser()?->getId();
			}
		}

		return $attachmentMap;
	}

	private function getAttachments(array $attachmentIds): ?AttachedObjectCollection
	{
		Collection::normalizeArrayValuesByInt($attachmentIds, false);

		if (empty($attachmentIds))
		{
			return null;
		}

		/** @var AttachedObjectCollection $attachments */
		$attachments = AttachedObjectCollection::createByIds(...$attachmentIds);

		return $attachments;
	}

	private function getCurrentTaskAttachments(int $taskId): DiskFileCollection
	{
		$current = $this->taskReadRepository->getAttachmentIds($taskId);

		return $this->getByIds($current);
	}
}
