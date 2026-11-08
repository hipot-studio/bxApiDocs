<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\OperationImpact;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Channel;
use Bitrix\BIConnector\Internal\Operation\Builder\Table\GetTableSchemaOperation;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

final class GetBiTableSchemaTool extends BaseBiTool
{
	public function getName(): string
	{
		return 'get_bi_table_schema';
	}

	public function getImpact(): OperationImpact
	{
		return OperationImpact::Read;
	}

	public function getDescription(): string
	{
		return 'Returns column metadata for a single BI table: column names and types. '
			. 'Call this after list_bi_tables to pick the columns to project into a virtual dataset. '
			. 'The returned column names are case-sensitive and must be passed verbatim to '
			. 'create_virtual_dataset.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'tableName' => [
					'type' => 'string',
					'description' => 'Physical table name as returned by list_bi_tables.',
				],
				'schema' => [
					'type' => 'string',
					'description' => 'Optional schema name. If omitted, the default BI schema is used.',
				],
			],
			'required' => ['tableName'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		if (!(new FlagGate())->isEnabled(FlagGate::BUILDER_MCP_OPERATIONS, $userId))
		{
			return $this->legacyExecuteStructured($userId, ...$args);
		}

		self::requireBiconnectorModule();

		$schema = isset($args['schema']) && is_string($args['schema']) && $args['schema'] !== ''
			? $args['schema']
			: null
		;

		// Resolved per call: aiassistant keeps a tool as a process-wide singleton.
		$result = ServiceLocator::getInstance()->get(GetTableSchemaOperation::class)->execute(
			(string)($args['tableName'] ?? ''),
			$schema,
			new Actor($userId, Channel::Mcp),
		);
		if (!$result->isSuccess())
		{
			throw self::toMcpException(OperationRefusal::forTool($result));
		}

		return $result->getData();
	}

	private function legacyExecuteStructured(int $userId, ...$args): array
	{
		Loader::includeModule('biconnector');

		if (!$this->getAccessController($userId)->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT))
		{
			throw new McpException('Access denied: report editing permission (ACTION_BIC_DASHBOARD_EDIT) is required to browse BI table schema.');
		}

		$tableName = trim((string)($args['tableName'] ?? ''));
		if ($tableName === '')
		{
			throw new McpException('tableName is required: provide the table name (call list_bi_tables to see available tables).');
		}

		$schema = isset($args['schema']) && is_string($args['schema']) && $args['schema'] !== ''
			? $args['schema']
			: null
		;

		$response = IntegratorFactory::getInstance()->getTableMetadata($tableName, $schema);
		if ($response->hasErrors())
		{
			throw self::unavailableBiException(
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

		return [
			'tableName' => $tableName,
			'schema' => (string)($data['schema'] ?? $schema ?? ''),
			'columns' => $columns,
		];
	}
}
