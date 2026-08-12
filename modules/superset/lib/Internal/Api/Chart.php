<?php

namespace Bitrix\Superset\Internal\Api;

use Bitrix\Main\Web\Json;
use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\RequestResult;

class Chart
{
	private const CHART_API_LINK = '/api/v1/chart/';

	private ?SupersetInstance $connector;

	public function __construct(SupersetInstance $connector)
	{
		$this->connector = $connector;
	}

	public function getChartById(int $id): RequestResult
	{
		$url = self::CHART_API_LINK . $id;
		return $this->connector->get($url);
	}

	/**
	 * Updates chart by id
	 *
	 * Fields for update:
	 * 	"cache_timeout": 0,
	 * 	"certification_details": "string",
	 * 	"certified_by": "string",
	 * 	"dashboards": [
	 * 		0
	 * 	],
	 * 	"datasource_id": 0,
	 * 	"datasource_type": "sl_table",
	 * 	"description": "string",
	 * 	"external_url": "string",
	 * 	"is_managed_externally": true,
	 * 	"owners": [
	 * 		0
	 * 	],
	 * 	"params": "string",
	 * 	"query_context": "string",
	 * 	"query_context_generation": true,
	 * 	"slice_name": "string",
	 * 	"tags": [
	 * 		{
	 * 			"id": 0,
	 * 			"name": "string",
	 * 			"type": 1
	 * 		}
	 * 	],
	 * 	"viz_type": [
	 * 		"bar",
	 * 		"area",
	 * 		"table"
	 * 	]
	 *
	 * @param int $id
	 * @param array $payload
	 * @return RequestResult
	 */
	public function updateChart(int $id, array $payload): RequestResult
	{
		$url = self::CHART_API_LINK . $id;
		return $this->connector->put($url, $payload);
	}

	/**
	 * Imports chart to Superset
	 * Overwrite flag supports only 'true' value
	 *
	 * @param string $pathToFile
	 * @return RequestResult
	 */
	public function importChart(string $pathToFile): RequestResult
	{
		$content = fopen($pathToFile, 'rb');
		$url = self::CHART_API_LINK . 'import/';
		$payload = [
			'formData' => $content,
			'overwrite' => 'true',
		];

		return $this->connector->postMultipart($url, $payload);
	}

	/**
	 * @param int $ownerId
	 *
	 * @return RequestResult
	 */
	public function getChartsByOwnerId(int $ownerId): RequestResult
	{
		$query = "(filters:!((col:owners,opr:rel_m_m,value:{$ownerId})))";
		$url = self::CHART_API_LINK . '?q=' . $query;

		return $this->connector->get($url);
	}

	/**
	 * Deletes multiple charts by ids
	 *
	 * @param int[] $ids
	 *
	 * @return RequestResult
	 */
	public function deleteCharts(array $ids): RequestResult
	{
		$url = self::CHART_API_LINK . '?q=!(' . implode(',', $ids) . ')';

		return $this->connector->delete($url);
	}

	public function getChartsList(?array $filter = null, ?int $page = null, ?int $pageSize = null): RequestResult
	{
		$url = self::CHART_API_LINK;

		$query = [];

		if ($filter)
		{
			$query['filters'] = $filter;
		}

		if ($page)
		{
			$query['page'] = $page;
		}

		if ($pageSize)
		{
			$query['page_size'] = $pageSize;
		}

		if ($query)
		{
			$query = Json::encode($query);
			$url = self::CHART_API_LINK . '?q=' . $query;
		}

		return $this->connector->get($url);
	}
}
