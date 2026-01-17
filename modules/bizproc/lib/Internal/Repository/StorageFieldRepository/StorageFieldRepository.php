<?php

namespace Bitrix\Bizproc\Internal\Repository\StorageFieldRepository;

use Bitrix\Bizproc\Internal\Entity;
use Bitrix\Bizproc\Internal\Model\EO_StorageField_Collection;
use Bitrix\Bizproc\Internal\Model\StorageFieldTable;
use Bitrix\Bizproc\Internal\Exception\StorageField\DeleteStorageFieldException;
use Bitrix\Bizproc\Internal\Exception\StorageField\CreateStorageFieldException;
use Bitrix\Bizproc\Internal\Repository\Mapper\StorageFieldMapper;
use Bitrix\Bizproc\Public\Provider\Params\StorageField\StorageFieldFilter;
use Bitrix\Main\ORM\Query\QueryHelper;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\Provider\Params\FilterInterface;

class StorageFieldRepository implements StorageFieldRepositoryInterface
{
	public function __construct(private readonly StorageFieldMapper $mapper)
	{
	}

	public function exists(int $id): bool
	{
		$item = $this->getList(
			limit: 1,
			filter: new StorageFieldFilter(['ID' => $id]),
			sort: ['ID' => 'ASC'],
			select: ['ID']
		)->getFirstCollectionItem();

		return (bool)$item;
	}

	public function getList(
		?int $limit = null,
		?int $offset = null,
		?FilterInterface $filter = null,
		?array $sort = null,
		?array $select = null,
	): Entity\StorageField\StorageFieldCollection
	{
		$query = StorageFieldTable::query()
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

		$ormStorageFields = QueryHelper::decompose($query);

		$storageFields = [];
		foreach ($ormStorageFields as $ormStorageField)
		{
			$storageFields[] = $this->mapper->convertFromOrm($ormStorageField);
		}

		return new Entity\StorageField\StorageFieldCollection(...$storageFields);
	}

	public function getCount(array $filter = [], array $cache = []): int
	{
		return StorageFieldTable::getCount($filter, $cache);
	}

	public function getById(int $id, array $select = []): ?Entity\StorageField\StorageField
	{
		return $this->getList(
			limit: 1,
			filter: new StorageFieldFilter(['ID' => $id]),
			select: $select
		)->getFirstCollectionItem();
	}

	public function getByStorageId(int $storageId, array $select = []): Entity\StorageField\StorageFieldCollection
	{
		$query =
			StorageFieldTable::query()
				->setSelect($select ?: ['*'])
				->where('STORAGE_ID', $storageId)
		;

		$ormStorageFields = QueryHelper::decompose($query);

		/** @var EO_StorageField_Collection $ormStorageFields */
		return $this->convertStorageFieldsFromOrm($ormStorageFields);
	}

	public function save(
		Entity\StorageField\StorageField $storageField,
		string $exceptionClass = null
	): AddResult|UpdateResult
	{
		$exceptionClass ??= CreateStorageFieldException::class;

		try
		{
			$ormStorageField = $this->mapper->convertToOrm($storageField);
			if (!$storageField->getStorageId())
			{
				throw new $exceptionClass();
			}

			$result = $ormStorageField->save();
			if (!$result->isSuccess())
			{
				throw new $exceptionClass($result->getErrors()[0]->getMessage());
			}

			if (!$result->getId())
			{
				throw new $exceptionClass();
			}

			if ($result->isSuccess() && $storageField->isNew())
			{
				$storageField->setId($result->getId());
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
			$result = StorageFieldTable::delete($id);
		}
		catch (\Throwable $e)
		{
			throw new DeleteStorageFieldException($e->getMessage());
		}

		if (!$result->isSuccess())
		{
			throw new DeleteStorageFieldException($result->getErrors()[0]->getMessage());
		}
	}

	public function deleteByStorageId(int $storageId): void
	{
		StorageFieldTable::deleteByFilter(['STORAGE_ID' => $storageId]);
	}

	private function convertStorageFieldsFromOrm(
		EO_StorageField_Collection $ormStorageFields
	): Entity\StorageField\StorageFieldCollection
	{
		$storageFields = [];
		foreach ($ormStorageFields as $ormStorageField)
		{
			$storageFields[] = $this->mapper->convertFromOrm($ormStorageField);
		}

		return new Entity\StorageField\StorageFieldCollection(...$storageFields);
	}
}
