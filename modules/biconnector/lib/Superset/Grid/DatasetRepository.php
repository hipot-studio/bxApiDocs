<?php

namespace Bitrix\BIConnector\Superset\Grid;

use Bitrix\BIConnector\ExternalSource\Internal\ExternalDatasetTable;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalSourceDatasetRelationTable;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalSourceTable;
use Bitrix\BIConnector\DataSource\SystemDatasetProvider;
use Bitrix\Main\ORM\Fields\Relations;
use Bitrix\Main\ORM\Query\Join;

class DatasetRepository
{
	private array $systemDatasetsCache = [];
	private array $userCountCache = [];

	public function getSystemDatasets(array $ormFilter = []): array
	{
		$cacheKey = serialize($ormFilter);
		if (array_key_exists($cacheKey, $this->systemDatasetsCache))
		{
			return $this->systemDatasetsCache[$cacheKey];
		}

		try
		{
			$this->systemDatasetsCache[$cacheKey] = (new SystemDatasetProvider())->getList($ormFilter);

			return $this->systemDatasetsCache[$cacheKey];
		}
		catch (\Throwable)
		{
			return [];
		}
	}

	public function getUserDatasets(array $select, array $filter, array $order, int $limit, int $offset): array
	{
		if (!in_array('EXTERNAL_ID', $select, true))
		{
			$select[] = 'EXTERNAL_ID';
		}

		$result = ExternalDatasetTable::query()
			->setSelect($select)
			->setFilter($filter)
			->registerRuntimeField(
				(new Relations\Reference(
					'SOURCE_RELATION',
					ExternalSourceDatasetRelationTable::class,
					Join::on('this.ID', 'ref.DATASET_ID')
				))
			)
			->registerRuntimeField(
				(new Relations\Reference(
					'SOURCE',
					ExternalSourceTable::class,
					Join::on('this.SOURCE_RELATION.SOURCE_ID', 'ref.ID')
				))
			)
			->setLimit($limit)
			->setOffset($offset)
			->setOrder($order)
			->exec()
		;

		$rows = [];
		$datasetIds = [];
		while ($row = $result->fetch())
		{
			$row['SOURCE'] = null;
			$row['IS_SYSTEM'] = false;
			$datasetIds[] = $row['ID'];
			$rows[] = $row;
		}

		if (!empty($datasetIds))
		{
			$sourceMap = $this->loadSourcesForDatasets($datasetIds);
			foreach ($rows as &$row)
			{
				$row['SOURCE'] = $sourceMap[$row['ID']] ?? null;
			}
			unset($row);
		}

		return $rows;
	}

	public function getUserDatasetsCount(array $filter): int
	{
		$cacheKey = serialize($filter);
		if (array_key_exists($cacheKey, $this->userCountCache))
		{
			return $this->userCountCache[$cacheKey];
		}

		$this->userCountCache[$cacheKey] = ExternalDatasetTable::query()
			->setSelect(['ID'])
			->setFilter($filter)
			->registerRuntimeField(
				(new Relations\Reference(
					'SOURCE_RELATION',
					ExternalSourceDatasetRelationTable::class,
					Join::on('this.ID', 'ref.DATASET_ID')
				))
			)
			->registerRuntimeField(
				(new Relations\Reference(
					'SOURCE',
					ExternalSourceTable::class,
					Join::on('this.SOURCE_RELATION.SOURCE_ID', 'ref.ID')
				))
			)
			->queryCountTotal()
		;

		return $this->userCountCache[$cacheKey];
	}

	public function getTotalCount(array $ormFilter): int
	{
		return $this->getUserDatasetsCount($ormFilter) + count($this->getSystemDatasets($ormFilter));
	}

	/**
	 * Builds a page of rows: user rows first, system rows fill the tail.
	 *
	 * @param array $ormParams Grid ORM params (select, filter, order).
	 * @param int $pageOffset Current page offset.
	 * @param int $pageLimit Current page size.
	 * @return array Rows for the current page.
	 */
	public function getPageRows(array $ormParams, int $pageOffset, int $pageLimit): array
	{
		$ormCount = $this->getUserDatasetsCount($ormParams['filter'] ?? []);
		$systemRows = $this->getSystemDatasets($ormParams['filter'] ?? []);

		if ($pageOffset >= $ormCount)
		{
			$systemOffset = $pageOffset - $ormCount;

			return array_slice($systemRows, $systemOffset, $pageLimit);
		}

		$userLimit = min($pageLimit, $ormCount - $pageOffset);
		$userRows = $this->getUserDatasets(
			$ormParams['select'] ?? [],
			$ormParams['filter'] ?? [],
			$ormParams['order'] ?? [],
			$userLimit,
			$pageOffset,
		);

		$freeSlots = $pageLimit - count($userRows);
		if ($freeSlots > 0)
		{
			$systemSlice = array_slice($systemRows, 0, $freeSlots);
			array_push($userRows, ...$systemSlice);
		}

		return $userRows;
	}

	private function loadSourcesForDatasets(array $datasetIds): array
	{
		$result = ExternalSourceDatasetRelationTable::query()
			->addSelect('DATASET_ID')
			->addSelect('SOURCE.ID', 'SRC_ID')
			->addSelect('SOURCE.TYPE', 'SRC_TYPE')
			->addSelect('SOURCE.TITLE', 'SRC_TITLE')
			->whereIn('DATASET_ID', $datasetIds)
			->exec()
		;

		$map = [];
		while ($row = $result->fetch())
		{
			$datasetId = $row['DATASET_ID'];
			if (isset($map[$datasetId]))
			{
				continue;
			}

			$map[$datasetId] = [
				'ID' => $row['SRC_ID'],
				'TYPE' => $row['SRC_TYPE'],
				'TITLE' => $row['SRC_TITLE'],
			];
		}

		return $map;
	}
}
