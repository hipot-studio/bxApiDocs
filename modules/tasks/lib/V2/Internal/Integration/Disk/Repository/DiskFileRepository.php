<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Disk\Repository;

use Bitrix\Disk\Type\AttachedObjectCollection;
use Bitrix\Disk\Uf\Integration\DiskUploaderController;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Entity\DiskFileCollection;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Repository\Mapper\DiskFileMapper;

class DiskFileRepository implements DiskFileRepositoryInterface
{
	public function __construct(
		private readonly DiskFileMapper $diskFileMapper
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

		Collection::normalizeArrayValuesByInt($attachmentIds, false);

		if (empty($attachmentIds))
		{
			return [];
		}

		$attachments = AttachedObjectCollection::createByIds(...$attachmentIds);

		$attachmentMap = [];
		foreach ($attachments as $attachment)
		{
			$attachmentMap[(int)$attachment->getObjectId()] = (int)$attachment->getId();
		}

		return $attachmentMap;
	}
}
