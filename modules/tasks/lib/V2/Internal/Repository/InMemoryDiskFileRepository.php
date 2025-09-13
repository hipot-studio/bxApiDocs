<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity;

class InMemoryDiskFileRepository implements DiskFileRepositoryInterface
{
	private DiskFileRepositoryInterface $diskFileRepository;

	private Entity\DiskFileCollection $cache;

	public function __construct(DiskFileRepository $diskFileRepository)
	{
		$this->diskFileRepository = $diskFileRepository;
		$this->cache = new Entity\DiskFileCollection();
	}

	public function getByIds(array $ids): Entity\DiskFileCollection
	{
		$files = Entity\DiskFileCollection::mapFromIds(ids: $ids, idKey: 'serverFileId');
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
}
