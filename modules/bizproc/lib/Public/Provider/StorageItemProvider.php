<?php

namespace Bitrix\Bizproc\Public\Provider;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\StorageItem;
use Bitrix\Bizproc\Internal\Repository\StorageItemRepository\StorageItemRepositoryInterface;
use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\Type\DateTime;

class StorageItemProvider
{
	private int $storageTypeId;
	private StorageItemRepositoryInterface $repository;

	public function __construct(int $storageTypeId)
	{
		$this->storageTypeId = $storageTypeId;
		$this->repository = Container::getStorageItemRepository();
	}

	public function getById(int $id, array $select = ['*']): ?StorageItem\StorageItem
	{
		return $this->repository->getItem($this->storageTypeId, $id, $select);
	}

	public function getItems(array $parameters = []): ?StorageItem\StorageItemCollection
	{
		return $this->repository->getItems($this->storageTypeId, $parameters);
	}

	public function exists(int $id): bool
	{
		return $this->repository->exists($id);
	}

	public function getList(GridParams $gridParams): ?StorageItem\StorageItemCollection
	{
		return $this->repository->getList(
			storageTypeId: $this->storageTypeId,
			limit: $gridParams->getLimit(),
			offset: $gridParams->getOffset(),
			filter: $gridParams->filter,
			sort: $gridParams->getSort(),
			select: $gridParams->getSelect(),
		);
	}

	public function getCount(array $filter = []): int
	{
		return $this->repository->getCount($this->storageTypeId, $filter);
	}

	public function findOldStorageItemIds(DateTime $createdTime, ?int $limit = null): array
	{
		return $this->repository->findOldStorageItemIds($createdTime, $limit);
	}
}
