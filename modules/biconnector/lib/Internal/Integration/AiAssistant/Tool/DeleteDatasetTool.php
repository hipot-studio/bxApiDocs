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
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

final class DeleteDatasetTool extends BaseBiTool
{
	private const DELETION_IMPACT = 'The dataset will be permanently deleted. Any charts built on '
		. 'it will break. This cannot be undone.'
	;
	private const CONFIRM_HINT = 'Show this preview to the user; only AFTER they explicitly approve, '
		. 'call delete_dataset again with the same datasetId and confirm=true.'
	;

	public function getName(): string
	{
		return 'delete_dataset';
	}

	public function getImpact(): OperationImpact
	{
		return OperationImpact::Destructive;
	}

	public function getDescription(): string
	{
		return 'Permanently deletes a dataset from BI Constructor (Apache Superset). '
			. 'DESTRUCTIVE and irreversible. Any charts built on top of this dataset '
			. 'will break — delete or repoint them first. '
			. 'TWO-STEP CONFIRMATION (MANDATORY): the first call (omit `confirm`, or pass '
			. '`confirm=false`) returns a preview (dataset name) and changes nothing. Relay it '
			. 'to the user, get EXPLICIT approval, then call again with `confirm=true`. Never '
			. 'set `confirm=true` on your own, never chain the two calls, never infer approval '
			. 'from earlier conversation. '
			. 'Returns the preview on the first call, and `{deleted:true, id, name}` on '
			. 'the confirmed call. Idempotent: deleting an already-deleted dataset is a safe '
			. 'no-op and returns `{deleted:true, already_deleted:true}`.';
	}

	public function getInputSchema(): array
	{
		$schema = BuilderArgumentContract::schema(
			'biconnector.builder.dataset.delete',
			'mcp',
			[
				'datasetId' => 'Dataset id to delete (from list_datasets). Positive integer.',
			],
		);

		$schema['properties']['confirm'] = [
			'type' => 'boolean',
			'description' => 'Set to true ONLY after the user has explicitly approved '
				. 'this previewed deletion. Omit or set false to only preview the impact. '
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

		// Resolved per call: aiassistant keeps a tool as a process-wide singleton.
		$operation = ServiceLocator::getInstance()->get(WriteDatasetOperations::class);
		$actor = new Actor($userId, Channel::Mcp);

		$confirm = ($args['confirm'] ?? false) === true;
		if (!$confirm)
		{
			$preview = $operation->previewDelete($datasetId, $actor);
			if (!$preview->isSuccess())
			{
				throw self::toMcpException($preview);
			}
			$data = $preview->getData();

			if ($data['alreadyDeleted'])
			{
				return WriteDatasetOperations::deletionAnswer($data);
			}

			return [
				'requiresConfirmation' => true,
				'action' => 'delete_dataset',
				'id' => $data['id'],
				'name' => $data['name'],
				'impact' => self::DELETION_IMPACT,
				'confirmHint' => self::CONFIRM_HINT,
			];
		}

		$result = $operation->delete($datasetId, $actor);
		if (!$result->isSuccess())
		{
			throw self::toMcpException($result);
		}

		return WriteDatasetOperations::deletionAnswer($result->getData());
	}

	private function legacyExecuteStructured(int $userId, ...$args): array
	{
		Loader::includeModule('biconnector');

		if (!Feature::isBuilderEnabled())
		{
			throw new McpException('BI Builder feature is not enabled on this portal.');
		}

		$datasetId = (int)($args['datasetId'] ?? 0);
		if ($datasetId <= 0)
		{
			throw new McpException('datasetId is required and must be a positive integer.');
		}

		$accessController = $this->getAccessController($userId);
		if (!$accessController->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			throw new McpException('Access denied: ACTION_BIC_ACCESS is required.');
		}
		if (!$accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT))
		{
			throw new McpException('Access denied: ACTION_BIC_DASHBOARD_EDIT is required to delete datasets.');
		}

		$integrator = IntegratorFactory::getInstance();

		// Point-load the dataset (not a full list walk) to resolve its name and
		// owners for the object-level scope check and to confirm it exists before
		// a destructive call.
		$lookup = (new EntityLookup())->dataset($datasetId, self::findActAsUser($userId));
		if (!$lookup->isSuccess())
		{
			if ($lookup->getErrors()[0]->getCode() === FailureCode::NotFound->value)
			{
				return [
					'deleted' => true,
					'id' => $datasetId,
					'name' => '',
					'already_deleted' => true,
				];
			}

			throw self::toMcpException(OperationRefusal::forTool($lookup));
		}

		$current = $lookup->getData()['entity'];

		$name = (string)($current['table_name'] ?? '');

		// Object-level scope: only an owner of the dataset may delete it.
		$this->assertDatasetOwned($userId, is_array($current['owners'] ?? null) ? $current['owners'] : []);

		$confirm = ($args['confirm'] ?? false) === true;
		if (!$confirm)
		{
			return [
				'requiresConfirmation' => true,
				'action' => 'delete_dataset',
				'id' => $datasetId,
				'name' => $name,
				'impact' => 'The dataset will be permanently deleted. Any charts built on '
					. 'it will break. This cannot be undone.',
				'confirmHint' => 'Show this preview to the user; only AFTER they explicitly approve, call delete_dataset again with the same datasetId and confirm=true.',
			];
		}

		// Charged here and not earlier: a preview, a denied or an already-deleted
		// call returns above and must not cost the user a destructive slot.
		$this->spendDestructiveBudget($userId, 'dataset', $datasetId);

		$response = $integrator->deleteDataset($datasetId);
		$classified = (new EntityLookup())->classify($response, $datasetId);
		if (!$classified->isSuccess())
		{
			if ($classified->getErrors()[0]->getCode() === FailureCode::NotFound->value)
			{
				return [
					'deleted' => true,
					'id' => $datasetId,
					'name' => $name,
					'already_deleted' => true,
				];
			}

			throw self::toMcpException(OperationRefusal::forTool($classified));
		}

		return [
			'deleted' => true,
			'id' => $datasetId,
			'name' => $name,
		];
	}
}
