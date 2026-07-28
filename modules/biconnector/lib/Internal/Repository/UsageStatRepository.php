<?php

namespace Bitrix\BIConnector\Internal\Repository;

use Bitrix\BIConnector\Internal\Entity\UsageStatEntry;
use Bitrix\BIConnector\Internal\Entity\UsageStatEntryCollection;
use Bitrix\BIConnector\Internal\Model\UsageStatTable;
use Bitrix\BIConnector\Internal\Repository\Mapper\UsageStatMapper;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Provider\Params\FilterInterface;
use Bitrix\Main\Provider\Params\PrepareQueryInterface;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Repository\RepositoryInterface;

class UsageStatRepository implements RepositoryInterface
{
	private readonly UsageStatMapper $mapper;

	public function __construct()
	{
		$this->mapper = new UsageStatMapper();
	}

	public function getById(mixed $id): ?UsageStatEntry
	{
		$ormModel = UsageStatTable::getById($id)->fetchObject();

		return $ormModel !== null ? $this->mapper->convertFromOrm($ormModel) : null;
	}

	/**
	 * @throws PersistenceException
	 */
	public function save(EntityInterface $entity): void
	{
		if (!$entity instanceof UsageStatEntry)
		{
			throw new PersistenceException('Entity must be an instance of UsageStatEntry');
		}

		try
		{
			$result = $this->mapper->convertToOrm($entity)->save();
		}
		catch (\Exception $e)
		{
			throw new PersistenceException($e->getMessage(), previous: $e);
		}

		if ($result->isSuccess() && $entity->getId() === null && $result instanceof \Bitrix\Main\ORM\Data\AddResult)
		{
			$entity->setId($result->getId());
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				'Unable to save usage stat entry',
				errors: $result->getErrorMessages()
			);
		}
	}

	/**
	 * @throws PersistenceException
	 */
	public function delete(mixed $id): void
	{
		try
		{
			$result = UsageStatTable::delete($id);
		}
		catch (\Exception $e)
		{
			throw new PersistenceException($e->getMessage(), previous: $e);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				'Unable to delete usage stat entry',
				errors: $result->getErrorMessages()
			);
		}
	}

	public function getList(
		?int $limit = null,
		?int $offset = null,
		?FilterInterface $filter = null,
		?array $sort = null,
		?array $select = null,
	): UsageStatEntryCollection
	{
		$query = UsageStatTable::query()
			->setSelect($select ?: ['*'])
		;

		$this->applyFilter($query, $filter);

		if ($sort !== null && $sort !== [])
		{
			$sort = $this->applyLoadLevelSortAlias($query, $sort);
			$query->setOrder($sort);
		}

		if ($limit !== null)
		{
			$query->setLimit($limit);
		}

		if ($offset !== null)
		{
			$query->setOffset($offset);
		}

		$ormItems = $query->fetchCollection();

		$collection = new UsageStatEntryCollection();
		if (!$ormItems || $ormItems->isEmpty())
		{
			return $collection;
		}

		foreach ($ormItems as $ormItem)
		{
			$collection->add($this->mapper->convertFromOrm($ormItem));
		}

		return $collection;
	}

	public function getCount(?FilterInterface $filter = null): int
	{
		$query = UsageStatTable::query();

		$this->applyFilter($query, $filter);

		return $query->queryCountTotal();
	}

	/**
	 * @return array<string> Array of table codes.
	 */
	public function getUsedTables(?FilterInterface $filter = null): array
	{
		$query = UsageStatTable::query()
			->setSelect(['SOURCE_ID'])
			->where('SOURCE_ID', '!=', '')
			->setOrder(['SOURCE_ID' => 'ASC'])
			->setGroup(['SOURCE_ID'])
		;

		$this->applyFilter($query, $filter);

		$items = [];

		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$id = (string)($row['SOURCE_ID'] ?? '');
			if ($id === '' || isset($items[$id]))
			{
				continue;
			}

			$items[$id] = $id;
		}

		return array_keys($items);
	}

	/**
	 * @param string|null $searchQuery
	 * @param array|null $ids
	 * @param int $limit
	 *
	 * @return array<int|string, string> Map of dashboard id to its name.
	 */
	public function searchUsedDashboards(?string $searchQuery = null, ?array $ids = null, int $limit = 50): array
	{
		return $this->searchDistinctEntities('EXTERNAL_DASHBOARD_ID', 'EXTERNAL_DASHBOARD_NAME', $searchQuery, $ids, $limit);
	}

	/**
	 * @param string|null $searchQuery
	 * @param array|null $ids
	 * @param int $limit
	 *
	 * @return array<int|string, string> Map of dataset id to its name.
	 */
	public function searchUsedDatasets(?string $searchQuery = null, ?array $ids = null, int $limit = 50): array
	{
		return $this->searchDistinctEntities('EXTERNAL_DATASET_ID', 'EXTERNAL_DATASET_NAME', $searchQuery, $ids, $limit);
	}

	/**
	 * @param string|null $searchQuery
	 * @param array|null $ids
	 * @param int $limit
	 *
	 * @return array<string, array{name: string, type: 'chart'|'filter'}> Map of filter/chart id to its name and type.
	 */
	public function searchUsedCharts(?string $searchQuery = null, ?array $ids = null, int $limit = 50): array
	{
		$searchQuery = $this->normalizeSearchQuery($searchQuery);
		$ids = $this->normalizeIds($ids);

		$ormQuery = UsageStatTable::query()
			->setSelect([
				'EXTERNAL_CHART_ID',
				'EXTERNAL_CHART_NAME',
				'SOURCE',
				'LATEST_TS' => new ExpressionField('LATEST_TS', 'MAX(%s)', ['TIMESTAMP_X']),
			])
			->whereNotNull('EXTERNAL_CHART_ID')
			->where('EXTERNAL_CHART_ID', '!=', '')
			->setGroup(['EXTERNAL_CHART_ID', 'EXTERNAL_CHART_NAME', 'SOURCE'])
			->setOrder(['LATEST_TS' => 'DESC'])
		;

		if ($ids !== null)
		{
			$ormQuery->whereIn('EXTERNAL_CHART_ID', $ids);
		}
		else
		{
			if ($searchQuery !== null)
			{
				$ormQuery->whereLike('EXTERNAL_CHART_NAME', '%' . $searchQuery . '%');
			}
			$ormQuery->setLimit($limit);
		}

		$items = [];
		$queryResult = $ormQuery->exec();
		while ($row = $queryResult->fetch())
		{
			$id = (string)($row['EXTERNAL_CHART_ID'] ?? '');
			if ($id === '' || isset($items[$id]))
			{
				continue;
			}

			$name = trim((string)($row['EXTERNAL_CHART_NAME'] ?? ''));
			$items[$id] = [
				'name' => $name !== '' ? $name : $id,
				'type' => ((string)($row['SOURCE'] ?? '')) === 'chart' ? 'chart' : 'filter',
			];

			if ($ids === null && count($items) >= $limit)
			{
				break;
			}
		}

		return $items;
	}

	/**
	 * @param string[]|null $ids
	 *
	 * @return array<int|string, string>
	 */
	private function searchDistinctEntities(
		string $idField,
		string $nameField,
		?string $searchQuery,
		?array $ids,
		int $limit,
	): array
	{
		$searchQuery = $this->normalizeSearchQuery($searchQuery);
		$ids = $this->normalizeIds($ids);

		$ormQuery = UsageStatTable::query()
			->setSelect([
				$idField,
				$nameField,
				'LATEST_TS' => new ExpressionField('LATEST_TS', 'MAX(%s)', ['TIMESTAMP_X']),
			])
			->whereNotNull($idField)
			->where($idField, '!=', '')
			->setGroup([$idField, $nameField])
			->setOrder(['LATEST_TS' => 'DESC'])
		;

		if ($ids !== null)
		{
			$ormQuery->whereIn($idField, $ids);
		}
		else
		{
			if ($searchQuery !== null)
			{
				$ormQuery->whereLike($nameField, '%' . $searchQuery . '%');
			}
			$ormQuery->setLimit($limit);
		}

		$items = [];
		$queryResult = $ormQuery->exec();
		while ($row = $queryResult->fetch())
		{
			$id = (string)$row[$idField];
			if ($id === '' || isset($items[$id]))
			{
				continue;
			}

			$name = trim((string)$row[$nameField]);
			$items[$id] = $name !== '' ? $name : $id;

			if ($ids === null && count($items) >= $limit)
			{
				break;
			}
		}

		return $items;
	}

	/**
	 * @param string[]|null $ids
	 *
	 * @return string[]|null
	 */
	private function normalizeIds(?array $ids): ?array
	{
		if ($ids === null || $ids === [])
		{
			return null;
		}

		return array_values(array_map('strval', $ids));
	}

	private function normalizeSearchQuery(?string $searchQuery): ?string
	{
		if ($searchQuery === null)
		{
			return null;
		}

		$searchQuery = trim($searchQuery);

		return $searchQuery === '' ? null : $searchQuery;
	}

	/**
	 * Maps the virtual LOAD_LEVEL sort to a REAL_TIME-based SQL expression.
	 * Note: the exact Medium/High split by extra factors is not reproduced in SQL —
	 * this is the trade-off of computing the indicator on the fly.
	 *
	 * @param array<string, string> $sort
	 *
	 * @return array<string, string>
	 */
	private function applyLoadLevelSortAlias(\Bitrix\Main\ORM\Query\Query $query, array $sort): array
	{
		$result = [];
		foreach ($sort as $field => $direction)
		{
			if ($field === 'LOAD_LEVEL')
			{
				$result['REAL_TIME'] = $direction;

				continue;
			}

			$result[$field] = $direction;
		}

		return $result;
	}

	private function applyFilter(\Bitrix\Main\ORM\Query\Query $query, ?FilterInterface $filter): void
	{
		if ($filter === null)
		{
			return;
		}

		if ($filter instanceof PrepareQueryInterface)
		{
			$filter->prepareQuery($query);
		}

		$conditionTree = $filter->prepareFilter();
		if ($conditionTree->hasConditions())
		{
			$query->where($conditionTree);
		}
	}
}
