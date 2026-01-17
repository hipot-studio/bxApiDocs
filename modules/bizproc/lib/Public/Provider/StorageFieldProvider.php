<?php

namespace Bitrix\Bizproc\Public\Provider;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\StorageField\StorageField;
use Bitrix\Bizproc\Internal\Entity\StorageField\StorageFieldCollection;
use Bitrix\Bizproc\Internal\Repository\StorageFieldRepository\StorageFieldRepositoryInterface;
use Bitrix\Main\Provider\Params\GridParams;

class StorageFieldProvider
{
	private StorageFieldRepositoryInterface $repository;

	public function __construct()
	{
		$this->repository = Container::getStorageFieldRepository();
	}

	public function exists(int $id): bool
	{
		return $this->repository->exists($id);
	}

	public function getById(int $id, array $select = []): ?StorageField
	{
		return $this->repository->getById($id, $select);
	}

	public function getByStorageId(int $id, array $select = []): StorageFieldCollection
	{
		return $this->repository->getByStorageId($id, $select);
	}

	public function getList(GridParams $gridParams): StorageFieldCollection
	{
		return $this->repository->getList(
			limit: $gridParams->getLimit(),
			offset: $gridParams->getOffset(),
			filter: $gridParams->filter,
			sort: $gridParams->getSort(),
			select: $gridParams->getSelect(),
		);
	}

	public function getCount(array $filter = [], array $cache = []): int
	{
		return $this->repository->getCount($filter, $cache);
	}
}
