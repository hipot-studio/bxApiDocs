<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\OperationImpact;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Channel;
use Bitrix\BIConnector\Internal\Operation\Builder\Dataset\WriteDatasetOperations;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

final class CreateVirtualDatasetTool extends BaseBiTool
{
	public function getName(): string
	{
		return 'create_virtual_dataset';
	}

	public function getImpact(): OperationImpact
	{
		return OperationImpact::Modify;
	}

	public function getDescription(): string
	{
		return 'Creates a virtual (SQL-backed) dataset in Superset based on a physical BI table '
			. 'and an explicit list of columns to project. '
			. 'Workflow: 1) call list_bi_tables to find the source table, '
			. '2) call get_bi_table_schema to inspect its columns, '
			. '3) call this tool with the chosen columns. '
			. 'The dataset name must be unique on the portal — call list_datasets first to verify. '
			. 'Returns the new dataset `id`, `name`, generated `sql`, `source_table`, '
			. '`source_schema` and the list of projected `columns`.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'datasetName' => [
					'type' => 'string',
					'minLength' => 1,
					'description' => 'Display name for the new virtual dataset. Must be unique on the portal.',
				],
				'sourceTable' => [
					'type' => 'string',
					'minLength' => 1,
					'description' => 'Physical table name (from list_bi_tables) used as the data source.',
				],
				'columns' => [
					'type' => 'array',
					'description' => 'Column names to project. Use names exactly as returned by '
						. 'get_bi_table_schema. Each column must match `^[A-Za-z_][A-Za-z0-9_]*$`.',
					'items' => [
						'type' => 'string',
						'minLength' => 1,
						'pattern' => '^[A-Za-z_][A-Za-z0-9_]*$',
					],
					'minItems' => 1,
				],
				'sourceSchema' => [
					'type' => 'string',
					'description' => 'Optional source table schema. If omitted, the default BI schema is used.',
				],
			],
			'required' => ['datasetName', 'sourceTable', 'columns'],
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

		$columns = $args['columns'] ?? [];
		$sourceSchema = isset($args['sourceSchema']) && is_string($args['sourceSchema']) && $args['sourceSchema'] !== ''
			? $args['sourceSchema']
			: null
		;

		// Resolved per call: aiassistant keeps a tool as a process-wide singleton.
		$result = ServiceLocator::getInstance()->get(WriteDatasetOperations::class)->createFromTable(
			(string)($args['datasetName'] ?? ''),
			(string)($args['sourceTable'] ?? ''),
			is_array($columns) ? $columns : [],
			$sourceSchema,
			new Actor($userId, Channel::Mcp),
		);
		if (!$result->isSuccess())
		{
			throw self::toMcpException($result);
		}

		return $result->getData();
	}

	private function legacyExecuteStructured(int $userId, ...$args): array
	{
		Loader::includeModule('biconnector');

		if (!Feature::isBuilderEnabled())
		{
			throw new McpException('BI Builder feature is not enabled on this portal.');
		}

		$accessController = $this->getAccessController($userId);
		if (!$accessController->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			throw new McpException('Access denied: ACTION_BIC_ACCESS is required.');
		}
		if (!$accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT))
		{
			throw new McpException('Access denied: ACTION_BIC_DASHBOARD_EDIT is required.');
		}

		$datasetName = trim((string)($args['datasetName'] ?? ''));
		$sourceTable = trim((string)($args['sourceTable'] ?? ''));
		$columns = $args['columns'] ?? [];
		$sourceSchema = isset($args['sourceSchema']) && is_string($args['sourceSchema']) && $args['sourceSchema'] !== ''
			? $args['sourceSchema']
			: null
		;

		if ($datasetName === '')
		{
			throw new McpException('datasetName is required: provide a unique name for the new dataset.');
		}
		if ($sourceTable === '')
		{
			throw new McpException('sourceTable is required: provide the source table name (call list_bi_tables to see available tables).');
		}
		if (!is_array($columns) || empty($columns))
		{
			throw new McpException('At least one column must be provided.');
		}

		$columns = array_values(array_filter(
			array_map(static fn ($c): string => is_string($c) ? trim($c) : '', $columns),
			static fn (string $c): bool => $c !== '',
		));
		if (empty($columns))
		{
			throw new McpException('At least one non-empty column must be provided.');
		}

		// Idempotency: dataset names must be unique on the portal. Superset
		// would reject the duplicate later anyway, but checking up front lets
		// us return a clear, actionable error to the agent (with the existing
		// id) instead of a generic backend failure surfaced via
		// unavailableBiException. Point lookup by name instead of walking
		// the full listDatasets() so the cost does not grow with the number of
		// datasets on the instance. The name is taken only when a real dataset
		// (id > 0) comes back; a not-found response carries errors and falls
		// through to creation.
		$integrator = IntegratorFactory::getInstance();
		$existingResponse = $integrator->getDatasetByName($datasetName);
		if (!$existingResponse->hasErrors())
		{
			$existingData = $existingResponse->getData();
			$existingId = is_array($existingData) ? (int)($existingData['id'] ?? 0) : 0;
			if ($existingId > 0)
			{
				throw new McpException(
					"Dataset with name '{$datasetName}' already exists (id={$existingId}). "
					. 'Dataset names must be unique on the portal. '
					. 'Pick a different name or reuse the existing dataset id when creating charts.'
				);
			}
		}

		$response = $integrator->createVirtualDataset(
			$datasetName,
			$sourceTable,
			$columns,
			$sourceSchema,
		);
		if ($response->hasErrors())
		{
			throw self::unavailableBiException(
				$response->getErrors(),
				[
					'stage' => 'create_virtual_dataset',
					'user_id' => $userId,
					'dataset_name' => $datasetName,
					'source_table' => $sourceTable,
					'source_schema' => $sourceSchema,
				],
			);
		}

		$data = $response->getData();
		if (!is_array($data))
		{
			$data = [];
		}

		return [
			'id' => (int)($data['id'] ?? 0),
			'name' => (string)($data['name'] ?? $datasetName),
			'sql' => (string)($data['sql'] ?? ''),
			'source_table' => (string)($data['sourceTable'] ?? $sourceTable),
			'source_schema' => (string)($data['sourceSchema'] ?? $sourceSchema ?? ''),
			'columns' => $data['columns'] ?? $columns,
		];
	}
}
