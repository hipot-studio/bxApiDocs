<?php

namespace Bitrix\Superset\Internal\Api;

use Bitrix\Main;
use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\RequestResult;

class Dataset
{
	private const DATASET_API_LINK = '/api/v1/dataset/';

	private ?SupersetInstance $connector;

	public function __construct(SupersetInstance $connector)
	{
		$this->connector = $connector;
	}

	public function getDatasetById(int $id): RequestResult
	{
		$url = self::DATASET_API_LINK . $id;
		return $this->connector->get($url);
	}

	public function getDatasetByName(string $name): RequestResult
	{
		$query = [
			'filters' => [
				[
					'col' => 'table_name',
					'opr' => 'eq',
					'value' => $name,
				],
			],
		];
		$query = Main\Web\Json::encode($query);
		$url = self::DATASET_API_LINK . '?q=' . $query;

		return $this->connector->get($url);
	}

	/**
	 * Creates new dataset
	 *
	 * {
	 * "always_filter_main_dttm": false,
	 * "database": 0,
	 * "external_url": "string",
	 * "is_managed_externally": true,
	 * "normalize_columns": false,
	 * "owners": [
	 * 		0
	 * ],
	 * "schema": "string",
	 * "sql": "string",
	 * "table_name": "string"
	 * }
	 *
	 * @param array $payload
	 * @return RequestResult
	 */
	public function createDataset(array $payload): RequestResult
	{
		$url = self::DATASET_API_LINK;

		return $this->connector->post($url, $payload);
	}

	/**
	 * Updates dataset by id
	 *
	 * 	"cache_timeout": 0,
	 * 	"columns": [
	 * 		{
	 * 			"advanced_data_type": "string",
	 * 			"column_name": "string",
	 * 			"description": "string",
	 * 			"expression": "string",
	 * 			"extra": "string",
	 * 			"filterable": true,
	 * 			"groupby": true,
	 * 			"id": 0,
	 * 			"is_active": true,
	 * 			"is_dttm": true,
	 * 			"python_date_format": "string",
	 * 			"type": "string",
	 * 			"uuid": "3fa85f64-5717-4562-b3fc-2c963f66afa6",
	 * 			"verbose_name": "string"
	 * 		}
	 * 	],
	 * 	"database_id": 0,
	 * 	"default_endpoint": "string",
	 * 	"description": "string",
	 * 	"external_url": "string",
	 * 	"extra": "string",
	 * 	"fetch_values_predicate": "string",
	 * 	"filter_select_enabled": true,
	 * 	"is_managed_externally": true,
	 * 	"is_sqllab_view": true,
	 * 	"main_dttm_col": "string",
	 * 	"metrics": [
	 * 		{
	 * 			"currency": "string",
	 * 			"d3format": "string",
	 * 			"description": "string",
	 * 			"expression": "string",
	 * 			"extra": "string",
	 * 			"id": 0,
	 * 			"metric_name": "string",
	 * 			"metric_type": "string",
	 * 			"uuid": "3fa85f64-5717-4562-b3fc-2c963f66afa6",
	 * 			"verbose_name": "string",
	 * 			"warning_text": "string"
	 * 		}
	 * 	],
	 * 	"normalize_columns": true,
	 * 	"offset": 0,
	 * 	"owners": [
	 * 		0
	 * 	],
	 * 	"schema": "string",
	 * 	"sql": "string",
	 * 	"table_name": "string",
	 * 	"template_params": "string"
	 *
	 * @param int $id
	 * @param array $payload
	 * @param bool $overrideColumns
	 * @return RequestResult
	 */
	public function updateDataset(int $id, array $payload, bool $overrideColumns = false): RequestResult
	{
		$url = self::DATASET_API_LINK . $id;
		if ($overrideColumns)
		{
			$url .= '?override_columns=true';
		}

		return $this->connector->put($url, $payload);
	}

	/**
	 * Deletes dataset by id
	 *
	 * @param int $id
	 * @return RequestResult
	 */
	public function deleteDataset(int $id): RequestResult
	{
		$url = self::DATASET_API_LINK . $id;

		return $this->connector->delete($url);
	}

	/**
	 * Deletes multiple datasets by ids
	 *
	 * @param int[] $ids
	 * @return RequestResult
	 */
	public function deleteDatasets(array $ids): RequestResult
	{
		$url = self::DATASET_API_LINK . '?q=!(' . implode(',', $ids) . ')';

		return $this->connector->delete($url);
	}

	/**
	 * Imports dataset to Superset
	 * Overwrite flag supports only 'true' value
	 *
	 * @param string $pathToFile
	 * @return RequestResult
	 */
	public function importDataset(string $pathToFile): RequestResult
	{
		$content = fopen($pathToFile, 'rb');
		$url = self::DATASET_API_LINK . 'import/';
		$payload = [
			'formData' => $content,
			'overwrite' => 'true',
		];

		return $this->connector->postMultipart($url, $payload);
	}

	public function getDatasetsList(?array $filter = null, ?int $page = null, ?int $pageSize = null): RequestResult
	{
		$url = self::DATASET_API_LINK;

		$query = [];

		if ($filter)
		{
			$query['filters'] = $filter;
		}

		if ($page || $pageSize)
		{
			if ($page)
			{
				$query['page'] = $page;
			}

			if ($pageSize)
			{
				$query['page_size'] = $pageSize;
			}
		}

		if ($query)
		{
			$query = Main\Web\Json::encode($query);
			$url = self::DATASET_API_LINK . '?q=' . $query;
		}

		return $this->connector->get($url);
	}

	public function getDatasetsByOwnerId(int $ownerId): RequestResult
	{
		$query = "(filters:!((col:owners,opr:rel_m_m,value:{$ownerId})))";
		$url = self::DATASET_API_LINK . '?q=' . $query;

		return $this->connector->get($url);
	}


	/**
	 * Get datasets (virtual&physical) of table in database
	 *
	 * @param int $databaseId
	 * @param string $tableName
	 * @return RequestResult
	 */
	public function getRelatedDatasetsFromTable(int $databaseId, string $tableName): RequestResult
	{
		$query = "(table_name:'" . $tableName . "',database_id:" . $databaseId . ")";
		$url = self::DATASET_API_LINK . "datasets_for_table/" . '?q=' . $query;

		return $this->connector->get($url);
	}
}
