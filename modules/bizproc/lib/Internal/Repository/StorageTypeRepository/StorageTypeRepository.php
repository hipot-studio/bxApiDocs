<?php

namespace Bitrix\Bizproc\Internal\Repository\StorageTypeRepository;

use Bitrix\Bizproc\Internal\Entity;
use Bitrix\Bizproc\Internal\Model\EO_StorageType_Collection;
use Bitrix\Bizproc\Internal\Model\StorageTypeTable;
use Bitrix\Bizproc\Internal\Exception\StorageType\DeleteStorageTypeException;
use Bitrix\Bizproc\Internal\Exception\StorageType\CreateStorageTypeException;
use Bitrix\Bizproc\Internal\Repository\Mapper\StorageTypeMapper;
use Bitrix\Bizproc\Public\Provider\Params\StorageType\StorageTypeFilter;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
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

	public function getCount(?FilterInterface $filter = null): int
	{
		$query = StorageTypeTable::query();

		if ($filter !== null)
		{
			$query->where($filter->prepareFilter());
		}

		return $query->queryCountTotal();
	}

	public function getPosition(int $id, ?FilterInterface $filter = null, ?array $sort = null): ?int
	{
		$order = $sort ?: ['ID' => 'ASC'];

		$query = StorageTypeTable::query()->setSelect(array_keys($order))->where('ID', $id);
		if ($filter !== null)
		{
			$query->where($filter->prepareFilter());
		}

		$row = $query->fetch();
		if ($row === false)
		{
			return null;
		}

		$precedingCondition = $this->buildPrecedingCondition($order, $row);
		if ($precedingCondition === null)
		{
			return 0;
		}

		$countQuery = StorageTypeTable::query()->where($precedingCondition);
		if ($filter !== null)
		{
			$countQuery->where($filter->prepareFilter());
		}

		return $countQuery->queryCountTotal();
	}

	/**
	 * Rows preceding the given one under $order: NULLs are placed as MySQL does — first for ASC, last for DESC.
	 */
	private function buildPrecedingCondition(array $order, array $row): ?ConditionTree
	{
		$condition = Query::filter()->logic('or');
		$equalValues = [];

		foreach ($order as $column => $direction)
		{
			$value = $row[$column] ?? null;
			$isAscending = strtoupper((string)$direction) !== 'DESC';

			if ($value === null && $isAscending)
			{
				$equalValues[$column] = null;

				continue;
			}

			$branch = Query::filter();
			foreach ($equalValues as $equalColumn => $equalValue)
			{
				$equalValue === null
					? $branch->whereNull($equalColumn)
					: $branch->where($equalColumn, $equalValue);
			}

			if ($value === null)
			{
				$branch->whereNotNull($column);
			}
			elseif ($isAscending)
			{
				$branch->where(
					Query::filter()->logic('or')->where($column, '<', $value)->whereNull($column)
				);
			}
			else
			{
				$branch->where($column, '>', $value);
			}

			$condition->where($branch);
			$equalValues[$column] = $value;
		}

		return $condition->hasConditions() ? $condition : null;
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

	public function getStoragesByFilter(array $filter, array $select): Entity\StorageType\StorageTypeCollection
	{
		$query =
			StorageTypeTable::query()
				->setSelect($select)
				->setFilter($filter)
		;
		$ormStorageTypes = QueryHelper::decompose($query);

		/** @var EO_StorageType_Collection $ormStorageTypes */
		return $this->convertStorageTypesFromOrm($ormStorageTypes);
	}

	/**
	 * Exact CODE lookup, served by the unique ix_bp_storage_type_code index. Deliberately not routed through
	 * StorageTypeFilter: its CODE condition is the substring search of the admin grid, which both scans the
	 * index and can answer with a storage whose code merely contains the requested one. The whole row is
	 * selected because the mapper reads every field - a narrower select would trade this single query for
	 * lazy per-field loads.
	 */
	public function getByCode(string $code): ?Entity\StorageType\StorageType
	{
		if ($code === '')
		{
			return null;
		}

		$ormStorageType = StorageTypeTable::query()
			->setSelect(['*'])
			->setFilter(['=CODE' => $code])
			->setLimit(1)
			->fetchObject()
		;

		return $ormStorageType === null ? null : $this->mapper->convertFromOrm($ormStorageType);
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
