<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Chart;

use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Chart\ChartParamsBuilder;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\EntityLookup;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\BIConnector\Internal\Operation\Builder\SupersetIdentity;
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
	private readonly SupersetIdentity $identity;

	public function __construct(?ChartWriteGuard $guard = null, ?SupersetIdentity $identity = null)
	{
		$this->guard = $guard ?? new ChartWriteGuard();
		$this->identity = $identity ?? new SupersetIdentity();
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
		$classified = (new EntityLookup())->classify($response, $chartId);
		if (!$classified->isSuccess())
		{
			return $classified;
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

		$identityResult = $this->identity->forRead($actor->userId);
		if (!$identityResult->isSuccess())
		{
			return $identityResult;
		}

		$lookup = (new EntityLookup())->chart($chartId, $identityResult->getData()['user']);
		if (!$lookup->isSuccess())
		{
			return $lookup;
		}

		$chartData = $lookup->getData()['entity'];
		if (!is_array($chartData['dashboards'] ?? []))
		{
			return Refusal::unavailableBi([], ['stage' => 'replace_chart_config.load', 'chart_id' => $chartId]);
		}

		$editable = $this->guard->checkEditable(
			$chartData['dashboards'] ?? [],
			$actor,
		);
		if (!$editable->isSuccess())
		{
			return $editable;
		}

		$dataset = (new ChartDatasetGuard())->validate($config, $identityResult->getData()['user']);
		if (!$dataset->isSuccess())
		{
			return $dataset;
		}

		return (new Result())->setData([
			'payload' => $payload,
			'name' => (string)($chartData['slice_name'] ?? $chartData['chart_name'] ?? ''),
		]);
	}
}
