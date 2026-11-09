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
use Bitrix\BIConnector\Internal\Operation\Builder\EntityLookup;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

final class UpdateDatasetTool extends BaseBiTool
{
	private const ALLOWED_COLUMN_TYPES = ['INT', 'STRING', 'DOUBLE', 'DATE', 'DATETIME'];
	private const UPDATE_IMPACT = 'The dataset column set will be fully replaced with the columns above. '
		. 'Columns not listed will be dropped. Charts relying on dropped columns may break.'
	;
	private const CONFIRM_HINT = 'Show this preview to the user; only AFTER they explicitly approve, '
		. 'call update_dataset again with the same datasetId, the same columns and confirm=true.'
	;

	public function getName(): string
	{
		return 'update_dataset';
	}

	public function getImpact(): OperationImpact
	{
		return OperationImpact::Modify;
	}

	public function getDescription(): string
	{
		return 'Updates the projected `columns` of an existing Superset dataset by its `id`. '
			. 'Call get_dataset / list_datasets first to discover the dataset and its current columns. '
			. 'The `columns` list fully replaces the projected column set: include every column the '
			. 'dataset should keep — columns omitted here are dropped and charts relying on them may '
			. 'break. Each column needs a `name` and a `type` (one of '
			. implode(', ', self::ALLOWED_COLUMN_TYPES) . '). '
			. 'TWO-STEP CONFIRMATION (MANDATORY): the first call (omit `confirm`, or pass '
			. '`confirm=false`) returns a preview (which dataset and which columns will be set) '
			. 'and changes nothing. Relay it to the user, get EXPLICIT approval, then call '
			. 'again with `confirm=true`. Never set `confirm=true` on your own, never chain the '
			. 'two calls, never infer approval from earlier conversation. '
			. 'Returns the preview on the first call, and `{updated:true, id, name, columns}` '
			. 'on the confirmed call.';
	}

	public function getInputSchema(): array
	{
		$schema = BuilderArgumentContract::schema(
			'biconnector.builder.dataset.update',
			'mcp',
			[
				'datasetId' => 'Dataset id to update (from list_datasets / get_dataset). Positive integer.',
				'columns' => 'Full replacement list of columns to project. Include every column the '
					. 'dataset should keep — columns omitted here are dropped from the dataset.',
				'columns[].name' => 'Column name. Trimmed and must be nonblank.',
				'columns[].type' => 'Column data type.',
			],
		);

		$schema['properties']['confirm'] = [
			'type' => 'boolean',
			'description' => 'Set to true ONLY after the user has explicitly approved '
				. 'this dataset update. Omit or set false to only preview the change. '
				. 'Do NOT self-confirm or assume approval from earlier context.',
		];

		return $schema;
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		if (!(new FlagGate())->isEnabled(FlagGate::BUILDER_MCP_OPERATIONS, $userId))
		{
			return $this->legacyExecuteStructured($userId, ...$args);
		}

		self::requireBiconnectorModule();

		$datasetId = (int)($args['datasetId'] ?? 0);
		$columns = $args['columns'] ?? [];
		$columns = is_array($columns) ? $columns : [];

		// Resolved per call: aiassistant keeps a tool as a process-wide singleton.
		$operation = ServiceLocator::getInstance()->get(WriteDatasetOperations::class);
		$actor = new Actor($userId, Channel::Mcp);

		$confirm = ($args['confirm'] ?? false) === true;
		if (!$confirm)
		{
			$preview = $operation->previewUpdate($datasetId, $columns, $actor);
			if (!$preview->isSuccess())
			{
				throw self::toMcpException($preview);
			}
			$data = $preview->getData();

			return [
				'requiresConfirmation' => true,
				'action' => 'update_dataset',
				'id' => $data['id'],
				'name' => $data['name'],
				'columns' => $data['columns'],
				'impact' => self::UPDATE_IMPACT,
				'confirmHint' => self::CONFIRM_HINT,
			];
		}

		$result = $operation->update($datasetId, $columns, $actor);
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
			throw new McpException('Access denied: ACTION_BIC_DASHBOARD_EDIT is required to update datasets.');
		}

		$datasetId = (int)($args['datasetId'] ?? 0);
		if ($datasetId <= 0)
		{
			throw new McpException('datasetId is required and must be a positive integer.');
		}

		$columns = $this->normalizeColumns($args['columns'] ?? []);

		$integrator = IntegratorFactory::getInstance();

		// Confirm the dataset exists and resolve its name for a meaningful preview
		// before attempting a mutation.
		$lookup = (new EntityLookup())->dataset($datasetId, self::findActAsUser($userId));
		if (!$lookup->isSuccess())
		{
			throw self::toMcpException(OperationRefusal::forTool($lookup));
		}

		$current = $lookup->getData()['entity'];

		$name = (string)($current['table_name'] ?? '');

		// Object-level scope: only an owner of the dataset may modify it.
		$this->assertDatasetOwned($userId, is_array($current['owners'] ?? null) ? $current['owners'] : []);

		$confirm = ($args['confirm'] ?? false) === true;
		if (!$confirm)
		{
			return [
				'requiresConfirmation' => true,
				'action' => 'update_dataset',
				'id' => $datasetId,
				'name' => $name,
				'columns' => $columns,
				'impact' => 'The dataset column set will be fully replaced with the columns above. '
					. 'Columns not listed will be dropped. Charts relying on dropped columns may break.',
				'confirmHint' => 'Show this preview to the user; only AFTER they explicitly approve, call update_dataset again with the same datasetId, the same columns and confirm=true.',
			];
		}

		$response = $integrator->updateDataset($datasetId, ['columns' => $columns]);
		$classified = (new EntityLookup())->classify($response, $datasetId);
		if (!$classified->isSuccess())
		{
			throw self::toMcpException(OperationRefusal::forTool($classified));
		}

		return [
			'updated' => true,
			'id' => $datasetId,
			'name' => $name,
			'columns' => $columns,
		];
	}

	/**
	 * @return array<int, array{name: string, type: string}>
	 */
	private function normalizeColumns(mixed $rawColumns): array
	{
		if (!is_array($rawColumns) || empty($rawColumns))
		{
			throw new McpException('At least one column must be provided.');
		}

		$columns = [];
		foreach ($rawColumns as $rawColumn)
		{
			if (!is_array($rawColumn))
			{
				throw new McpException('Each column must be an object with `name` and `type`.');
			}

			$columnName = trim((string)($rawColumn['name'] ?? ''));
			$columnType = strtoupper(trim((string)($rawColumn['type'] ?? '')));

			if ($columnName === '')
			{
				throw new McpException('Each column must have a non-empty `name`.');
			}
			if (!in_array($columnType, self::ALLOWED_COLUMN_TYPES, true))
			{
				throw new McpException(
					"Unsupported column type '{$columnType}' for column '{$columnName}'. "
					. 'Allowed types: ' . implode(', ', self::ALLOWED_COLUMN_TYPES) . '.'
				);
			}

			$columns[] = [
				'name' => $columnName,
				'type' => $columnType,
			];
		}

		return $columns;
	}
}
