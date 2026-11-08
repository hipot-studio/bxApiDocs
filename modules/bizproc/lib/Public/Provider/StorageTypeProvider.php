<?php

namespace Bitrix\Bizproc\Public\Provider;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\StorageType\StorageType;
use Bitrix\Bizproc\Internal\Entity\StorageType\StorageTypeCollection;
use Bitrix\Bizproc\Internal\Repository\StorageTypeRepository\StorageTypeRepositoryInterface;
use Bitrix\Main\Provider\Params\FilterInterface;
use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\Provider\Params\SortInterface;

class StorageTypeProvider
{
	private StorageTypeRepositoryInterface $repository;

	public function __construct()
	{
		$this->repository = Container::getStorageTypeRepository();
	}

	public function exists(int $id): bool
	{
		return $this->repository->exists($id);
	}

	public function getById(int $id, array $select = []): ?StorageType
	{
		return $this->repository->getById($id, $select);
	}

	public function getType(array $filter = [], array $select = []): ?StorageType
	{
		return $this->repository->getType($filter, $select);
	}

	/**
	 * Exact resolve by CODE, unlike the substring search of the admin grid filter (StorageTypeFilter['CODE']).
	 */
	public function getByCode(string $code): ?StorageType
	{
		return $this->repository->getByCode($code);
	}

	public function getCount(?FilterInterface $filter = null): int
	{
		return $this->repository->getCount($filter);
	}

	public function getList(GridParams $gridParams): StorageTypeCollection
	{
		return $this->repository->getList(
			limit: $gridParams->getLimit(),
			offset: $gridParams->getOffset(),
			filter: $gridParams->filter,
			sort: $gridParams->getSort(),
			select: $gridParams->getSelect(),
		);
	}

	public function getPosition(int $id, ?FilterInterface $filter = null, ?SortInterface $sort = null): ?int
	{
		return $this->repository->getPosition($id, $filter, $sort?->prepareSort());
	}

	public function getAllForActivity(): StorageTypeCollection
	{
		return $this->repository->getAllForActivity();
	}

	public function getStoragesByFilter(array $filter = [], array $select = ['*']): StorageTypeCollection
	{
		return $this->repository->getStoragesByFilter($filter, $select);
	}
}
