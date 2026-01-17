<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Disk\Repository;

use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Entity\DiskFileCollection;

class InMemoryDiskFileRepository implements DiskFileRepositoryInterface
{
	private DiskFileRepositoryInterface $diskFileRepository;

	private DiskFileCollection $cache;
	private array $objectToAttachmentCache = [];

	public function __construct(DiskFileRepository $diskFileRepository)
	{
		$this->diskFileRepository = $diskFileRepository;
		$this->cache = new DiskFileCollection();
	}

	public function getByIds(array $ids): DiskFileCollection
	{
		$files = DiskFileCollection::mapFromIds(ids: $ids, idKey: 'serverFileId');
		$stored = $this->cache->findAllByIds($ids);

		$notStoredIds = $files->diff($stored)->getIdList();

		if (empty($notStoredIds))
		{
			return $stored;
		}

		$files = $this->diskFileRepository->getByIds($notStoredIds);

		$this->cache->merge($files);

		return $files;
	}

	public function getObjectIdsByAttachmentIds(array $attachmentIds): array
	{
		Collection::normalizeArrayValuesByInt($attachmentIds, false);

		$notStored = array_diff($attachmentIds, $this->objectToAttachmentCache);

		if (!empty($notStored))
		{
			$attachmentMap = $this->diskFileRepository->getObjectIdsByAttachmentIds($notStored);
			$this->objectToAttachmentCache += $attachmentMap;
		}

		return array_filter(
			$this->objectToAttachmentCache,
			static fn (int $attachmentId): bool => in_array($attachmentId, $attachmentIds, true)
		);
	}
}
