<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Table;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\Main\Result;

/**
 * Columns of a physical table of the BI database, the answer of get_bi_table_schema: `tableName`, `schema`
 * and `columns`.
 *
 * The right to edit dashboards is checked globally, as get_bi_table_schema does it: the axis of the table
 * rights is not applied here, it belongs to S2/S3 of PRD-bi-dataset-permissions-2026-07-30.
 */
final class GetTableSchemaOperation
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

	/**
	 * @param string|null $schema Schema of the table; null or blank means the default BI schema.
	 */
	public function execute(string $tableName, ?string $schema, Actor $actor): Result
	{
		$userId = $actor->userId;

		if (!($this->accessControllerFactory)($userId)->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT))
		{
			return Refusal::error(
				FailureCode::AccessDenied,
				'Access denied: report editing permission (ACTION_BIC_DASHBOARD_EDIT) is required '
					. 'to browse BI table schema.',
			);
		}

		$tableName = trim($tableName);
		if ($tableName === '')
		{
			return Refusal::error(
				FailureCode::InvalidArgument,
				'tableName is required: provide the table name (call list_bi_tables to see available tables).',
			);
		}

		$schema = $schema !== '' ? $schema : null;

		$response = IntegratorFactory::getInstance()->getTableMetadata($tableName, $schema);
		if ($response->hasErrors())
		{
			return Refusal::unavailableBi(
				$response->getErrors(),
				[
					'stage' => 'get_bi_table_schema',
					'user_id' => $userId,
					'table' => $tableName,
					'schema' => $schema,
				],
			);
		}

		$data = $response->getData();
		if (!is_array($data))
		{
			$data = [];
		}

		$columns = [];
		foreach ((is_array($data['columns'] ?? null) ? $data['columns'] : []) as $column)
		{
			if (!is_array($column))
			{
				continue;
			}

			$columns[] = [
				'name' => (string)($column['name'] ?? ''),
				'type' => (string)($column['type'] ?? ''),
				'nullable' => (bool)($column['nullable'] ?? true),
			];
		}

		return (new Result())->setData([
			'tableName' => $tableName,
			'schema' => (string)($data['schema'] ?? $schema ?? ''),
			'columns' => $columns,
		]);
	}
}
