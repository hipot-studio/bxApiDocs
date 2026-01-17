<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity;

class InMemoryFileRepository implements FileRepositoryInterface
{
	private FileRepositoryInterface $fileRepository;

	private Entity\FileCollection $cache;

	public function __construct(FileRepository $fileRepository)
	{
		$this->fileRepository = $fileRepository;
		$this->cache = new Entity\FileCollection();
	}

	public function getById(int $id): ?Entity\File
	{
		$file = $this->cache->findOneById($id);
		if (!$file)
		{
			$file = $this->fileRepository->getById($id);
			if ($file !== null)
			{
				$this->cache->add($file);
			}
		}

		return $file;
	}

	public function getByIds(array $ids): Entity\FileCollection
	{
		$files = Entity\FileCollection::mapFromIds($ids);
		$stored = $this->cache->findAllByIds($ids);

		$notStoredIds = $files->diff($stored)->getIdList();

		if (empty($notStoredIds))
		{
			return $stored;
		}

		$files = $this->fileRepository->getByIds($notStoredIds);

		$this->cache->merge($files);

		return $files;
	}
}
