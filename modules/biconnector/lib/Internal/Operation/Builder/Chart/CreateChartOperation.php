<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Chart;

use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Chart\ChartParamsBuilder;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\BIConnector\Internal\Operation\Builder\SupersetIdentity;
use Bitrix\Main\Result;

/**
 * Creates a Superset chart over a dataset from the high-level config of create_chart, with its checks and texts.
 * The new chart is on no dashboard yet, so the right is the global ACTION_BIC_DASHBOARD_EDIT.
 */
final class CreateChartOperation
{
	private readonly ChartWriteGuard $guard;
	private readonly SupersetIdentity $identity;

	public function __construct(?ChartWriteGuard $guard = null, ?SupersetIdentity $identity = null)
	{
		$this->guard = $guard ?? new ChartWriteGuard();
		$this->identity = $identity ?? new SupersetIdentity();
	}

	/**
	 * @param array $config The arguments of create_chart: `name`, `datasetId`, `vizType`, `temporalColumn`,
	 *     `metrics`, `dimensions`, `xAxis`, `filters`, `description`, `rowLimit`.
	 *
	 * Data: the answer of create_chart — `id`, `name`, `viz_type`, `dataset_id`.
	 */
	public function execute(array $config, Actor $actor): Result
	{
		$allowed = $this->guard->forBuilder($actor);
		if (!$allowed->isSuccess())
		{
			return $allowed;
		}

		if (!$this->guard->can(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT, $actor))
		{
			return Refusal::error(
				FailureCode::AccessDenied,
				'Access denied: ACTION_BIC_DASHBOARD_EDIT is required to create charts.',
			);
		}

		$name = trim((string)($config['name'] ?? ''));
		if ($name === '')
		{
			return Refusal::error(FailureCode::InvalidArgument, 'name is required and must be a non-empty string.');
		}

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

		$identityResult = $this->identity->forRead($actor->userId);
		if (!$identityResult->isSuccess())
		{
			return $identityResult;
		}

		$dataset = (new ChartDatasetGuard())->validate($config, $identityResult->getData()['user'], true);
		if (!$dataset->isSuccess())
		{
			return $dataset;
		}

		$response = IntegratorFactory::getInstance()->createChart($payload);
		if ($response->hasErrors())
		{
			return ChartWriteGuard::unavailableBi(
				$response->getErrors(),
				[
					'stage' => 'create_chart',
					'user_id' => $actor->userId,
					'dataset_id' => (int)($config['datasetId'] ?? 0),
					'viz_type' => (string)($config['vizType'] ?? ''),
					'name' => (string)($config['name'] ?? ''),
				],
			);
		}

		$data = $response->getData();
		if (!is_array($data))
		{
			$data = [];
		}

		return (new Result())->setData([
			'id' => (int)($data['id'] ?? 0),
			'name' => (string)($data['chart_name'] ?? $data['slice_name'] ?? $config['name'] ?? ''),
			'viz_type' => (string)($data['viz_type'] ?? $payload['viz_type'] ?? ''),
			'dataset_id' => (int)($data['dataset_id'] ?? $data['datasource_id'] ?? $config['datasetId'] ?? 0),
		]);
	}
}
