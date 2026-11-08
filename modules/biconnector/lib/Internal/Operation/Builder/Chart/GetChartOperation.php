<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Chart;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

/**
 * Configuration of one Superset chart, the answer of get_chart: name, description, visualization type,
 * source dataset, ownership, the consumer dashboards and `params` trimmed to the fields that drive the query.
 *
 * Known gap: only ACTION_BIC_ACCESS is checked, so a chart of a foreign dashboard is read whole. It is kept
 * on purpose, as get_chart does it today; closing it belongs to S2/S3 of PRD-bi-dataset-permissions-2026-07-30.
 */
final class GetChartOperation
{
	/**
	 * Keys kept from the raw Superset `params` blob: those ChartParamsBuilder writes for the supported
	 * vizTypes plus a few legacy/UI keys of charts created in the Superset UI. Decorative keys are dropped,
	 * replace_chart_config accepts only the same fields.
	 */
	private const PARAMS_WHITELIST = [
		// Identity
		'viz_type',
		'datasource',
		// Aggregations and projection
		'metrics',
		'metric',
		'groupby',
		'columns',
		'all_columns',
		'query_mode',
		// Axes / temporal binding
		'x_axis',
		'granularity_sqla',
		'temporal_columns_lookup',
		// Filtering and time range
		'adhoc_filters',
		'time_range',
		// Limits / ordering
		'row_limit',
		'order_desc',
		'orderby',
	];

	/** @var \Closure(int): AccessController */
	private readonly \Closure $accessControllerFactory;

	/**
	 * @param (\Closure(int): AccessController)|null $accessControllerFactory
	 */
	public function __construct(?\Closure $accessControllerFactory = null)
	{
		$this->accessControllerFactory = $accessControllerFactory
			?? static fn(int $userId): AccessController => new AccessController($userId)
		;
	}

	public function execute(int $chartId, Actor $actor): Result
	{
		$userId = $actor->userId;

		if (!($this->accessControllerFactory)($userId)->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			return Refusal::error(FailureCode::AccessDenied, 'Access denied: ACTION_BIC_ACCESS is required.');
		}

		if ($chartId <= 0)
		{
			return Refusal::error(
				FailureCode::InvalidArgument,
				'chartId is required and must be a positive integer.',
			);
		}

		$response = IntegratorFactory::getInstance()->getChartById($chartId);
		if ($response->hasErrors())
		{
			return Refusal::unavailableBi(
				$response->getErrors(),
				['stage' => 'get_chart', 'user_id' => $userId, 'chart_id' => $chartId],
			);
		}

		$data = $response->getData();
		if (!is_array($data))
		{
			$data = [];
		}

		return (new Result())->setData([
			'id' => (int)($data['id'] ?? $chartId),
			'name' => (string)($data['slice_name'] ?? $data['chart_name'] ?? ''),
			'description' => (string)($data['description'] ?? ''),
			'viz_type' => (string)($data['viz_type'] ?? ''),
			'dataset_id' => (int)($data['datasource_id'] ?? $data['dataset_id'] ?? 0),
			'datasource_type' => (string)($data['datasource_type'] ?? 'table'),
			'params' => self::trimParams($data['params'] ?? null),
			'owners' => $data['owners'] ?? [],
			'dashboards' => self::trimDashboards($data['dashboards'] ?? []),
		]);
	}

	/**
	 * The whitelisted part of `params`; a blob that is not JSON comes back as it is.
	 */
	private static function trimParams(mixed $rawParams): mixed
	{
		if (is_string($rawParams) && $rawParams !== '')
		{
			try
			{
				$decoded = Json::decode($rawParams);

				return is_array($decoded)
					? array_intersect_key($decoded, array_flip(self::PARAMS_WHITELIST))
					: $decoded
				;
			}
			catch (\Throwable)
			{
				return $rawParams;
			}
		}

		if (is_array($rawParams))
		{
			return array_intersect_key($rawParams, array_flip(self::PARAMS_WHITELIST));
		}

		return null;
	}

	/**
	 * The consumer dashboards reduced to `id` and `title`: the raw payload carries the whole `json_metadata`
	 * of each, which is large and irrelevant when inspecting a chart.
	 *
	 * @return array<int, array{id: int, title: string}>
	 */
	private static function trimDashboards(mixed $dashboards): array
	{
		if (!is_array($dashboards))
		{
			return [];
		}

		$result = [];
		foreach ($dashboards as $dashboard)
		{
			if (!is_array($dashboard))
			{
				continue;
			}

			$result[] = [
				'id' => (int)($dashboard['id'] ?? 0),
				'title' => (string)($dashboard['dashboard_title'] ?? $dashboard['title'] ?? ''),
			];
		}

		return $result;
	}
}
