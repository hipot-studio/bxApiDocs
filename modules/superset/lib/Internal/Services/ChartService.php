<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main;
use Bitrix\Superset\Internal\Api\Chart;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\Support\AbstractSupersetContext;

final class ChartService extends AbstractSupersetContext
{
	public function list(array $ids = []): Main\Result
	{
		$filter = [];
		if (!empty($ids))
		{
			$filter[] = [
				'col' => 'id',
				'opr' => 'in',
				'value' => [$ids],
			];
		}

		$preparedCharts = [];
		$page = 0;
		$chartApi = $this->getChartApi();

		do
		{
			$requestResult = $chartApi->getChartsList($filter, $page, 100);
			if ($requestResult->getHttpStatus() !== HttpStatus::OK)
			{
				return $this->createRequestErrorResult($requestResult, 'Get charts list');
			}

			$charts = $this->decode($requestResult->getAnswer());
			if (!is_array($charts))
			{
				return $this->createErrorResult('Invalid chart list response');
			}

			foreach (($charts['result'] ?? []) as $chart)
			{
				if (is_array($chart))
				{
					$preparedCharts[] = $this->prepareResultChart($chart);
				}
			}

			$isRepeatRequest = count($charts['result'] ?? []) === 100;
			$page++;
		}
		while ($isRepeatRequest);

		$result = new Main\Result();
		$result->setData([
			'charts' => $this->mapUsersToClientIds($preparedCharts),
			'count' => count($preparedCharts),
		]);

		return $result;
	}

	public function deleteMany(array $ids): Main\Result
	{
		$result = new Main\Result();
		if (empty($ids))
		{
			$result->setData([
				'deleted_ids' => [],
			]);

			return $result;
		}

		$requestResult = $this->getChartApi()->deleteCharts($ids);
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Delete charts');
		}

		$result->setData([
			'deleted_ids' => array_values(array_map('intval', $ids)),
			'body' => $requestResult->getAnswer(),
		]);

		return $result;
	}

	public function get(int $id): Main\Result
	{
		$requestResult = $this->getChartApi()->getChartById($id);
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Get chart');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		if (!is_array($decoded) || !is_array($decoded['result'] ?? null))
		{
			return $this->createErrorResult('Invalid chart response');
		}

		$chart = $this->prepareResultChart($decoded['result']);
		$chart = current($this->mapUsersToClientIds([$chart])) ?: $chart;

		$result = new Main\Result();
		$result->setData([
			'chart' => $chart,
		]);

		return $result;
	}

	public function update(int $id, array $payload): Main\Result
	{
		$requestResult = $this->getChartApi()->updateChart($id, $payload);
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Update chart');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		$result = new Main\Result();
		$result->setData([
			'chart' => is_array($decoded) ? ($decoded['result'] ?? $decoded) : null,
			'body' => $requestResult->getAnswer(),
		]);

		return $result;
	}

	public function replaceOwner(int $fromOwnerId, array $replacementOwnerIds, int $maxExecutionTime = 0): Main\Result
	{
		$requestResult = $this->getChartApi()->getChartsByOwnerId($fromOwnerId);
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Getting charts by owner');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		if (!is_array($decoded))
		{
			return $this->createErrorResult('Invalid chart owner replacement response');
		}

		$isUpdated = false;
		$timeStart = Main\Diag\Helper::getCurrentMicrotime();

		foreach (($decoded['result'] ?? []) as $chart)
		{
			if (!is_array($chart))
			{
				continue;
			}

			$ownerIds = array_map('intval', array_column($chart['owners'] ?? [], 'id'));
			if (!in_array($fromOwnerId, $ownerIds, true))
			{
				continue;
			}

			$isUpdated = true;
			if (count($ownerIds) > 1)
			{
				$key = array_search($fromOwnerId, $ownerIds, true);
				if ($key !== false)
				{
					unset($ownerIds[$key]);
				}
			}
			else
			{
				$ownerIds = $replacementOwnerIds;
			}

			$ownerIds = array_values(array_unique(array_map('intval', $ownerIds)));
			sort($ownerIds);

			$updateResult = $this->getChartApi()->updateChart((int)($chart['id'] ?? 0), ['owners' => $ownerIds]);
			if ($updateResult->getHttpStatus() !== HttpStatus::OK)
			{
				return $this->createRequestErrorResult($updateResult, 'Replacing chart owner');
			}

			if (
				$maxExecutionTime > 0
				&& (Main\Diag\Helper::getCurrentMicrotime() - $timeStart) > $maxExecutionTime
			)
			{
				break;
			}
		}

		$result = new Main\Result();
		$result->setData([
			'updated' => $isUpdated,
			'is_running' => $isUpdated,
		]);

		return $result;
	}

	private function prepareResultChart(array $supersetChart): array
	{
		$chartId = (int)($supersetChart['id'] ?? 0);

		return [
			'id' => $chartId,
			'chart_name' => $supersetChart['slice_name'] ?? '',
			'viz_type' => $supersetChart['viz_type'] ?? '',
			'description' => $supersetChart['description'] ?? '',
			'owners' => $supersetChart['owners'] ?? [],
			'dataset_id' => (int)($supersetChart['datasource_id'] ?? 0),
			'edit_url' => $this->connector->buildRequestUrl('/explore/?slice_id=' . $chartId),
		];
	}

	private function getChartApi(): Chart
	{
		return new Chart($this->connector);
	}
}
