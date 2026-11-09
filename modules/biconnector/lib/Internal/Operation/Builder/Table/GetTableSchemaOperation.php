<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Table;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Integrator\ActAsFailure;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\BIConnector\Internal\Operation\Builder\SupersetIdentity;
use Bitrix\Main\Result;

/**
 * Columns of a physical table of the BI database, the answer of get_bi_table_schema: `tableName`, `schema`
 * and `columns`.
 *
 * The right to edit dashboards is checked globally, as get_bi_table_schema does it. The table rights are applied
 * by Superset: while the table permission axis is on it reads as the actor, see SupersetIdentity.
 */
final class GetTableSchemaOperation
{
	public const TABLE_NOT_AVAILABLE_MESSAGE = 'Table "%s" is not found or is not available to the current user. '
		. 'Do not retry; call list_bi_tables to see the tables the user can read.'
	;

	/** @var \Closure(int): AccessController */
	private readonly \Closure $accessControllerFactory;
	private readonly SupersetIdentity $identity;

	/**
	 * @param (\Closure(int): AccessController)|null $accessControllerFactory
	 */
	public function __construct(?\Closure $accessControllerFactory = null, ?SupersetIdentity $identity = null)
	{
		$this->accessControllerFactory = $accessControllerFactory
			?? static fn(int $userId): AccessController => new AccessController($userId)
		;
		$this->identity = $identity ?? new SupersetIdentity();
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

		$identityResult = $this->identity->forRead($userId);
		if (!$identityResult->isSuccess())
		{
			return $identityResult;
		}

		$response = IntegratorFactory::getInstance()->getTableMetadata(
			$identityResult->getData()['user'],
			$tableName,
			$schema,
		);
		if ($response->hasErrors())
		{
			// Superset answers a table closed by the rights of the actor as it answers a missing one; both are told
			// alike, so that the answer does not confirm that a closed table exists. Not NotFound: the REST answer
			// of that code names a record by a numeric id, and a table has a name.
			if (
				$response->getStatus() === IntegratorResponse::STATUS_NOT_FOUND
				&& ActAsFailure::findMessage($response->getErrors()) === null
			)
			{
				return Refusal::error(
					FailureCode::AccessDenied,
					sprintf(self::TABLE_NOT_AVAILABLE_MESSAGE, $tableName),
				);
			}

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
