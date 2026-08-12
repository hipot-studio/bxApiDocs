<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main;
use Bitrix\Main\Web\Uri;
use Bitrix\Superset\Internal\Api\Chart;
use Bitrix\Superset\Internal\Api\Dataset;
use Bitrix\Superset\Internal\Api\UnusedElements;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\Support\AbstractSupersetContext;

final class UnusedElementsService extends AbstractSupersetContext
{
	public function get(array $ormParams = []): Main\Result
	{
		$requestResult = $this->getUnusedElementsApi()->getUnusedElements($ormParams);
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Getting unused elements list');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		$data = is_array($decoded) ? ($decoded['result'] ?? null) : null;
		if (!is_array($data))
		{
			return $this->createErrorResult('Invalid unused elements response');
		}

		$elements = [];
		foreach (($data['elements'] ?? []) as $element)
		{
			if (!is_array($element))
			{
				continue;
			}

			if (($element['type'] ?? null) === 'chart')
			{
				$url = new Uri($this->server->getHost() . '/explore/');
				$url->addParams([
					'slice_id' => (int)($element['external_id'] ?? 0),
				]);
				$element['open_url'] = $url->getLocator();
			}
			elseif (($element['type'] ?? null) === 'dataset')
			{
				$url = new Uri($this->server->getHost() . '/tablemodelview/list/');
				$url->addParams([
					'filters' => "(table_name:'{$element['name']}')",
				]);
				$element['open_url'] = $url->getLocator();
			}

			$elements[] = $element;
		}

		$result = new Main\Result();
		$result->setData([
			'count' => (int)($data['count'] ?? count($elements)),
			'unusedElements' => $this->mapUsersToClientIds($elements),
		]);

		return $result;
	}

	public function delete(array $elements): Main\Result
	{
		$chartIdsToDelete = [];
		$datasetIdsToDelete = [];

		foreach ($elements as $element)
		{
			if (!is_array($element))
			{
				continue;
			}

			if (($element['elementType'] ?? null) === 'chart')
			{
				$chartIdsToDelete[] = (int)($element['elementId'] ?? 0);
			}
			elseif (($element['elementType'] ?? null) === 'dataset')
			{
				$datasetIdsToDelete[] = (int)($element['elementId'] ?? 0);
			}
		}

		if (!empty($chartIdsToDelete))
		{
			$requestResult = $this->getChartApi()->deleteCharts($chartIdsToDelete);
			if ($requestResult->getHttpStatus() !== HttpStatus::OK)
			{
				return $this->createRequestErrorResult($requestResult, 'Delete unused charts');
			}
		}

		if (!empty($datasetIdsToDelete))
		{
			$requestResult = $this->getDatasetApi()->deleteDatasets($datasetIdsToDelete);
			if ($requestResult->getHttpStatus() !== HttpStatus::OK)
			{
				return $this->createRequestErrorResult($requestResult, 'Delete unused datasets');
			}
		}

		$result = new Main\Result();
		$result->setData([
			'body' => 'OK',
			'charts' => $chartIdsToDelete,
			'datasets' => $datasetIdsToDelete,
		]);

		return $result;
	}

	private function getUnusedElementsApi(): UnusedElements
	{
		return new UnusedElements($this->connector);
	}

	private function getChartApi(): Chart
	{
		return new Chart($this->connector);
	}

	private function getDatasetApi(): Dataset
	{
		return new Dataset($this->connector);
	}
}
