<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Dataset;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\Main\Result;

/**
 * Definition of a Superset dataset with its columns, the answer of get_dataset: `id`, `table_name`,
 * `description`, `is_virtual`, `sql` and `columns`.
 *
 * Known gap: only ACTION_BIC_ACCESS is checked, any dataset of the portal is read. It is kept on purpose,
 * as get_dataset does it today; closing it belongs to S2/S3 of PRD-bi-dataset-permissions-2026-07-30.
 */
final class GetDatasetOperation
{
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

	public function execute(int $datasetId, Actor $actor): Result
	{
		$userId = $actor->userId;

		if (!Feature::isBuilderEnabled())
		{
			return Refusal::error(FailureCode::FeatureDisabled, 'BI Builder feature is not enabled on this portal.');
		}

		if (!($this->accessControllerFactory)($userId)->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			return Refusal::error(FailureCode::AccessDenied, 'Access denied: ACTION_BIC_ACCESS is required.');
		}

		if ($datasetId <= 0)
		{
			return Refusal::error(
				FailureCode::InvalidArgument,
				'datasetId is required and must be a positive integer.',
			);
		}

		$response = IntegratorFactory::getInstance()->getDatasetById($datasetId);
		if ($response->hasErrors())
		{
			return Refusal::unavailableBi(
				$response->getErrors(),
				['stage' => 'get_dataset', 'user_id' => $userId, 'dataset_id' => $datasetId],
			);
		}

		$data = $response->getData();
		if (!is_array($data) || (int)($data['id'] ?? 0) <= 0)
		{
			return Refusal::error(
				FailureCode::NotFound,
				"Dataset with id={$datasetId} was not found. Call list_datasets to get a valid id.",
			);
		}

		$columns = [];
		foreach (is_array($data['columns'] ?? null) ? $data['columns'] : [] as $column)
		{
			if (!is_array($column))
			{
				continue;
			}

			$entry = [
				'name' => (string)($column['column_name'] ?? ''),
				'type' => (string)($column['type'] ?? ''),
				'is_dttm' => (bool)($column['is_dttm'] ?? false),
			];

			if (isset($column['verbose_name']) && $column['verbose_name'] !== '' && $column['verbose_name'] !== null)
			{
				$entry['verbose_name'] = (string)$column['verbose_name'];
			}

			if (isset($column['description']) && $column['description'] !== '' && $column['description'] !== null)
			{
				$entry['description'] = (string)$column['description'];
			}

			$columns[] = $entry;
		}

		$sql = (string)($data['sql'] ?? '');

		return (new Result())->setData([
			'id' => (int)($data['id'] ?? 0),
			'table_name' => (string)($data['table_name'] ?? ''),
			'description' => (string)($data['description'] ?? ''),
			'is_virtual' => $sql !== '',
			'sql' => $sql,
			'columns' => $columns,
		]);
	}
}
