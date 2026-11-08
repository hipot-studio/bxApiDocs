<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Chart;

use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Chart\ChartParamsBuilder;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\Main\Result;

/**
 * Full replace of a chart configuration, as replace_chart_config does it, with its checks and texts:
 * the payload is rebuilt the way create_chart builds it, without the name, which the replace keeps.
 */
final class ReplaceChartConfigOperation
{
	// ChartParamsBuilder::build() requires a name, the replace drops it from the payload.
	private const NAME_PLACEHOLDER = '__placeholder__';

	private readonly ChartWriteGuard $guard;

	public function __construct(?ChartWriteGuard $guard = null)
	{
		$this->guard = $guard ?? new ChartWriteGuard();
	}

	/**
	 * @param array $config The configuration of replace_chart_config: `datasetId`, `vizType`, `temporalColumn`,
	 *     `metrics`, `dimensions`, `xAxis`, `filters`, `rowLimit`.
	 *
	 * Data: `id`, `name`, `newVizType`, `newDatasetId`.
	 */
	public function preview(int $chartId, array $config, Actor $actor): Result
	{
		$loaded = $this->load($chartId, $config, $actor);
		if (!$loaded->isSuccess())
		{
			return $loaded;
		}

		['payload' => $payload, 'name' => $name] = $loaded->getData();

		return (new Result())->setData([
			'id' => $chartId,
			'name' => $name,
			'newVizType' => (string)($payload['viz_type'] ?? ($config['vizType'] ?? '')),
			'newDatasetId' => (int)($config['datasetId'] ?? 0),
		]);
	}

	/**
	 * Data: the answer of replace_chart_config — `id`, `name`, `viz_type`.
	 */
	public function execute(int $chartId, array $config, Actor $actor): Result
	{
		$loaded = $this->load($chartId, $config, $actor);
		if (!$loaded->isSuccess())
		{
			return $loaded;
		}

		['payload' => $payload, 'name' => $name] = $loaded->getData();

		$response = IntegratorFactory::getInstance()->updateChart($chartId, $payload);
		if ($response->hasErrors())
		{
			return ChartWriteGuard::unavailableBi(
				$response->getErrors(),
				[
					'stage' => 'replace_chart_config',
					'user_id' => $actor->userId,
					'chart_id' => $chartId,
					'dataset_id' => (int)($config['datasetId'] ?? 0),
					'viz_type' => (string)($config['vizType'] ?? ''),
				],
			);
		}

		$data = $response->getData();
		if (!is_array($data))
		{
			$data = [];
		}

		// The name comes from the chart loaded before the update: the payload has no name to echo back.
		return (new Result())->setData([
			'id' => $chartId,
			'name' => $name,
			'viz_type' => (string)($data['viz_type'] ?? $payload['viz_type'] ?? ''),
		]);
	}

	private function load(int $chartId, array $config, Actor $actor): Result
	{
		$allowed = $this->guard->forChart($chartId, $actor);
		if (!$allowed->isSuccess())
		{
			return $allowed;
		}

		$config['name'] = self::NAME_PLACEHOLDER;
		try
		{
			$payload = ChartParamsBuilder::build(
				$config,
				static fn(string $message): \Throwable => new ChartConfigRefusedException($message),
			);
		}
		catch (ChartConfigRefusedException $exception)
		{
			return Refusal::error(FailureCode::InvalidArgument, $exception->getMessage());
		}
		unset($payload['slice_name']);

		$chartResponse = IntegratorFactory::getInstance()->getChartById($chartId);
		if ($chartResponse->hasErrors())
		{
			return ChartWriteGuard::unavailableBi(
				$chartResponse->getErrors(),
				['stage' => 'replace_chart_config.load', 'user_id' => $actor->userId, 'chart_id' => $chartId],
			);
		}

		$chartData = $chartResponse->getData();
		if (!is_array($chartData))
		{
			$chartData = [];
		}

		$editable = $this->guard->checkEditable(
			is_array($chartData['dashboards'] ?? null) ? $chartData['dashboards'] : [],
			$actor,
		);
		if (!$editable->isSuccess())
		{
			return $editable;
		}

		return (new Result())->setData([
			'payload' => $payload,
			'name' => (string)($chartData['slice_name'] ?? $chartData['chart_name'] ?? ''),
		]);
	}
}
