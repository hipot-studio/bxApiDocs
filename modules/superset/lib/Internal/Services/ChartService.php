<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main;
use Bitrix\Superset\Internal\Api\Chart;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\Support\AbstractSupersetContext;

final class ChartService extends AbstractSupersetContext
{
	/**
	 * Lists charts.
	 *
	 * Two modes:
	 *  - bounded (when $pageSize is given): fetches exactly ONE page from Superset
	 *    and returns its `count` as the total number of matching charts. Use this
	 *    for paginated reads and name lookups — it does a single API request
	 *    regardless of how many charts the instance has.
	 *  - legacy (when $pageSize is null): walks every page and returns all matching
	 *    charts. Kept for the usage-statistics caller that passes a small $ids set.
	 *    Do NOT call it without $ids on a large instance — it is O(total charts).
	 *
	 * @param int[] $ids Restrict to these chart ids (Superset `id in`).
	 * @param string|null $nameFilter Restrict to charts whose name contains this (Superset `slice_name ct`).
	 * @param int|null $page Zero-based page index (bounded mode only).
	 * @param int|null $pageSize Page size; presence switches on bounded mode.
	 */
	public function list(array $ids = [], ?string $nameFilter = null, ?int $page = null, ?int $pageSize = null): Main\Result
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
		if ($nameFilter !== null && $nameFilter !== '')
		{
			$filter[] = [
				'col' => 'slice_name',
				'opr' => 'ct',
				'value' => $nameFilter,
			];
		}

		$chartApi = $this->getChartApi();

		if ($pageSize !== null)
		{
			$requestResult = $chartApi->getChartsList($filter, $page, $pageSize);
			if ($requestResult->getHttpStatus() !== HttpStatus::OK)
			{
				return $this->createRequestErrorResult($requestResult, 'Get charts list');
			}

			$charts = $this->decode($requestResult->getAnswer());
			if (!is_array($charts))
			{
				return $this->createErrorResult('Invalid chart list response');
			}

			$preparedCharts = [];
			foreach (($charts['result'] ?? []) as $chart)
			{
				if (is_array($chart))
				{
					$preparedCharts[] = $this->prepareResultChart($chart);
				}
			}

			$result = new Main\Result();
			$result->setData([
				'charts' => $this->mapUsersToClientIds($preparedCharts),
				'count' => (int)($charts['count'] ?? count($preparedCharts)),
			]);

			return $result;
		}

		$preparedCharts = [];
		$currentPage = 0;

		do
		{
			$requestResult = $chartApi->getChartsList($filter, $currentPage, 100);
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
			$currentPage++;
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
		// get() exposes the full single-chart config for inspection and editing
		// (the get_chart AI tool reads `params` to understand how the chart is
		// wired before replace_chart_config, and `dashboards` to see where it is
		// used). These are intentionally NOT added to prepareResultChart() — that
		// helper is shared with the list path, which must stay slim.
		$chart['params'] = $decoded['result']['params'] ?? null;
		$chart['dashboards'] = $decoded['result']['dashboards'] ?? [];
		$chart = current($this->mapUsersToClientIds([$chart])) ?: $chart;

		$result = new Main\Result();
		$result->setData([
			'chart' => $chart,
		]);

		return $result;
	}

	public function create(array $payload): Main\Result
	{
		$requestResult = $this->getChartApi()->createChart($payload);
		if ($requestResult->getHttpStatus() !== HttpStatus::CREATED)
		{
			return $this->createRequestErrorResult($requestResult, 'Create chart');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		if (!is_array($decoded))
		{
			return $this->createErrorResult('Invalid chart create response');
		}

		$chartPayload = is_array($decoded['result'] ?? null) ? $decoded['result'] : [];
		$chartPayload['id'] = (int)($decoded['id'] ?? $chartPayload['id'] ?? 0);

		$chart = $this->prepareResultChart($chartPayload);
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
