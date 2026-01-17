<?php

namespace Bitrix\Bizproc\Internal\Repository\StorageTypeRepository;

use Bitrix\Bizproc\Internal\Entity;
use Bitrix\Bizproc\Internal\Model\EO_StorageType_Collection;
use Bitrix\Bizproc\Internal\Model\StorageTypeTable;
use Bitrix\Bizproc\Internal\Exception\StorageType\DeleteStorageTypeException;
use Bitrix\Bizproc\Internal\Exception\StorageType\CreateStorageTypeException;
use Bitrix\Bizproc\Internal\Repository\Mapper\StorageTypeMapper;
use Bitrix\Bizproc\Public\Provider\Params\StorageType\StorageTypeFilter;
use Bitrix\Main\ORM\Query\QueryHelper;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\Provider\Params\FilterInterface;

class StorageTypeRepository implements StorageTypeRepositoryInterface
{
	public function __construct(private readonly StorageTypeMapper $mapper)
	{
	}

	public function exists(int $id): bool
	{
		$item = $this->getList(
			limit: 1,
			filter: new StorageTypeFilter(['ID' => $id]),
			sort: ['ID' => 'ASC'],
			select: ['ID']
		)->getFirstCollectionItem();

		return (bool)$item;
	}

	public function getType(array $filter = [], array $select = []): ?Entity\StorageType\StorageType
	{
		return $this->getList(
			limit: 1,
			filter: new StorageTypeFilter($filter),
			select: $select,
		)->getFirstCollectionItem();
	}

	public function getList(
		?int $limit = null,
		?int $offset = null,
		?FilterInterface $filter = null,
		?array $sort = null,
		?array $select = null,
	): Entity\StorageType\StorageTypeCollection
	{
		$query = StorageTypeTable::query()
			->setSelect($select ?: ['*'])
		;

		if ($limit !== null)
		{
			$query->setLimit($limit);
		}

		if ($offset !== null)
		{
			$query->setOffset($offset);
		}

		if ($filter !== null)
		{
			$query->where($filter->prepareFilter());
		}

		if ($sort !== null)
		{
			$query->setOrder($sort);
		}

		$ormStorageTypes = QueryHelper::decompose($query);

		/** @var EO_StorageType_Collection $ormStorageTypes */
		return $this->convertStorageTypesFromOrm($ormStorageTypes);
	}

	public function getAllForActivity(): Entity\StorageType\StorageTypeCollection
	{
		$query =
			StorageTypeTable::query()
				->setSelect([
					'ID',
					'TITLE',
				])
		;
		$ormStorageTypes = QueryHelper::decompose($query);

		/** @var EO_StorageType_Collection $ormStorageTypes */
		return $this->convertStorageTypesFromOrm($ormStorageTypes);
	}

	public function getById(int $id, array $select = []): ?Entity\StorageType\StorageType
	{
		return $this->getList(
			limit: 1,
			filter: new StorageTypeFilter(['ID' => $id]),
			select: $select,
		)->getFirstCollectionItem();
	}

	public function save(
		Entity\StorageType\StorageType $storageType,
		string $exceptionClass = null
	): AddResult|UpdateResult
	{
		$exceptionClass ??= CreateStorageTypeException::class;

		try
		{
			$ormStorageType = $this->mapper->convertToOrm($storageType);
			$result = $ormStorageType->save();
			if (!$result->isSuccess())
			{
				$exceptionClass = $exceptionClass ?? CreateStorageTypeException::class;

				throw new $exceptionClass($result->getErrors()[0]->getMessage());
			}

			if ($result->isSuccess() && $storageType->isNew())
			{
				$storageType->setId($result->getId());
			}

			return $result;
		}
		catch (\Throwable $exception)
		{
			throw new $exceptionClass($exception->getMessage());
		}
	}

	public function delete(int $id): void
	{
		try
		{
			$result = StorageTypeTable::delete($id);
		}
		catch (\Exception $e)
		{
			throw new DeleteStorageTypeException($e->getMessage());
		}

		if (!$result->isSuccess())
		{
			throw new DeleteStorageTypeException($result->getErrors()[0]->getMessage());
		}
	}

	private function convertStorageTypesFromOrm(
		EO_StorageType_Collection $ormStorageTypes
	): Entity\StorageType\StorageTypeCollection
	{
		$storageTypes = [];
		foreach ($ormStorageTypes as $ormStorageType)
		{
			$storageTypes[] = $this->mapper->convertFromOrm($ormStorageType);
		}

		return new Entity\StorageType\StorageTypeCollection(...$storageTypes);
	}
}
