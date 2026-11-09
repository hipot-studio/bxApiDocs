<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\OperationImpact;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Argument\BuilderArgumentContract;
use Bitrix\BIConnector\Internal\Operation\Builder\Channel;
use Bitrix\BIConnector\Internal\Operation\Builder\Dataset\WriteDatasetOperations;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

final class CreateDatasetFromSqlTool extends BaseBiTool
{
	public function getName(): string
	{
		return 'create_dataset_from_sql';
	}

	public function getImpact(): OperationImpact
	{
		return OperationImpact::Modify;
	}

	public function getDescription(): string
	{
		return 'Creates a virtual (SQL-backed) dataset in Superset from a raw SQL SELECT query. '
			. 'Use this for complex datasets that cannot be expressed as a plain column '
			. 'projection over a single table (joins, aggregations, computed columns, '
			. 'subqueries). For a simple "pick columns from one table" dataset use '
			. 'create_virtual_dataset instead. '
			. 'Workflow: 1) call list_bi_tables / get_bi_table_schema to learn the real '
			. 'table and column names, 2) call this tool with a full SELECT statement. '
			. 'Reference tables as "bitrix24"."TABLE_NAME". The dataset name must be '
			. 'unique on the portal. Returns the new dataset `id`, `name` and stored `sql`.';
	}

	public function getInputSchema(): array
	{
		return BuilderArgumentContract::schema(
			'biconnector.builder.dataset.add',
			'mcp',
			[
				'datasetName' => 'Display name for the new virtual dataset. Trimmed and must be nonblank '
					. 'and unique on the portal.',
				'sql' => 'Raw SQL SELECT statement used as the dataset source. Trimmed and must be nonblank. '
					. 'Must be a single SELECT valid for the Trino BI database.',
			],
		);
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		if (!(new FlagGate())->isEnabled(FlagGate::BUILDER_MCP_OPERATIONS, $userId))
		{
			return $this->legacyExecuteStructured($userId, ...$args);
		}

		self::requireBiconnectorModule();

		// Resolved per call: aiassistant keeps a tool as a process-wide singleton.
		$result = ServiceLocator::getInstance()->get(WriteDatasetOperations::class)->createFromSql(
			(string)($args['datasetName'] ?? ''),
			// Raw read bypasses the Proactive Filter (WAF), which otherwise mangles SQL keywords.
			(string)$this->getRawArgument('sql', $args),
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
		// Raw read bypasses the Proactive Filter (WAF), which otherwise mangles
		// SQL keywords (SELECT -> SEL ECT, FROM -> FR OM) in the parsed request.
		$sql = trim((string)$this->getRawArgument('sql', $args));

		if ($datasetName === '')
		{
			throw new McpException('datasetName is required: provide a unique name for the new dataset.');
		}
		if ($sql === '')
		{
			throw new McpException('sql is required: provide the SELECT query that defines the dataset.');
		}

		$integrator = IntegratorFactory::getInstance();

		// Idempotency: dataset names must be unique on the portal (same check as create_virtual_dataset).
		// Point lookup by name instead of walking the full listDatasets() so the cost
		// does not grow with the number of datasets on the instance. The name is taken
		// only when a real dataset (id > 0) comes back; a not-found response carries
		// errors and falls through to creation.
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

		$response = $integrator->createDatasetFromSql(self::requireSupersetUser($userId), $datasetName, $sql);
		if ($response->hasErrors())
		{
			throw self::unavailableBiException(
				$response->getErrors(),
				[
					'stage' => 'create_dataset_from_sql',
					'user_id' => $userId,
					'dataset_name' => $datasetName,
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
			'sql' => (string)($data['sql'] ?? $sql),
		];
	}
}
