<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess\RemoveRelations;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\ArrayProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\SmartProcessRelationsService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class RemoveRelationsTool extends AbstractTool
{
	private readonly SmartProcessRelationsService $service;

	public function __construct(
		TracedLogger $tracedLogger,
		?SmartProcessRelationsService $service = null,
	)
	{
		parent::__construct($tracedLogger);
		$this->service = $service ?? new SmartProcessRelationsService();
	}

	public function canList(int $userId): bool
	{
		return true;
	}

	public function canRun(int $userId): bool
	{
		return true;
	}

	protected function getDefinition(): ToolDefinition
	{
		$description =
			'Removes (unbinds) individual relations of a CRM smart process without touching the others.'
			. ' parentEntities are ENTITY_TYPE_IDs of entities in whose card the smart process appears'
			. ' (each is a PARENT of the smart process); childEntities are ENTITY_TYPE_IDs of children of'
			. ' the smart process. Predefined (system) relations are never removed. Use'
			. ' get_smart_process_relations to inspect the current set;'
			. ' use add_smart_process_relations to bind individual relations;'
			. ' use configure_smart_process_relations to set the full list at once.'
		;

		return (new ToolDefinition(
			name: 'remove_smart_process_relations',
			description: $description,
		))
			->setProperties([
				(new IntegerProperty(
					'smartProcessId',
					'ENTITY_TYPE_ID of the smart process. Use search_dynamic_type to find it.',
				))
					->setIsRequired(true)
				,
				(new ArrayProperty(
					'parentEntities',
					'ENTITY_TYPE_IDs of PARENT relations to unbind (smart process shown in their card).',
				))
					->setArrayItemProperty(
						new IntegerProperty('parentEntityTypeId', 'ENTITY_TYPE_ID of the parent entity.'),
					)
				,
				(new ArrayProperty(
					'childEntities',
					'ENTITY_TYPE_IDs of CHILD relations to unbind.',
				))
					->setArrayItemProperty(
						new IntegerProperty('childEntityTypeId', 'ENTITY_TYPE_ID of the child entity.'),
					)
				,
			])
		;
	}

	protected function getArgsDtoClass(): string
	{
		return RemoveRelationsToolDto::class;
	}

	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		/** @var RemoveRelationsToolDto $args */
		$smartProcessId = $args->smartProcessId;

		$type = Container::getInstance()->getTypeByEntityTypeId($smartProcessId);
		if ($type === null)
		{
			return ToolResult::fail(
				"Smart process with ENTITY_TYPE_ID={$smartProcessId} was not found.",
			);
		}

		$canUpdate = Container::getInstance()
			->getUserPermissions($args->getUserId())
			->dynamicType()
			->canUpdate($smartProcessId)
		;
		if (!$canUpdate)
		{
			return ToolResult::fail(
				"Access denied: you do not have permission to manage smart process"
					. " with ENTITY_TYPE_ID={$smartProcessId}.",
			);
		}

		$report = [];
		foreach ([
			SmartProcessRelationsService::DIRECTION_PARENT => $args->parentEntities,
			SmartProcessRelationsService::DIRECTION_CHILD => $args->childEntities,
		] as $direction => $entityTypeIds)
		{
			if ($entityTypeIds === null)
			{
				continue;
			}

			$report[$direction] = $this->processDirection($smartProcessId, $direction, $entityTypeIds);
		}

		return ToolResult::success(
			smartProcessId: $smartProcessId,
			hasRejections: $this->service->hasRejections($report),
			result: $report,
		);
	}

	/**
	 * @param int[] $entityTypeIds
	 */
	private function processDirection(
		int $smartProcessId,
		string $direction,
		array $entityTypeIds
	): array
	{
		$current = $this->service->currentRelations($smartProcessId, $direction);
		$predefinedTargetIds = $this->service->predefinedTargetIds($smartProcessId, $direction);

		$removed = [];
		$skipped = [];
		$rejected = [];

		foreach ($entityTypeIds as $entityTypeId)
		{
			$entityTypeId = (int)$entityTypeId;

			if (isset($predefinedTargetIds[$entityTypeId]))
			{
				$rejected[] = [
					'entityTypeId' => $entityTypeId,
					'reason' => 'Relation is predefined (system) and can not be removed.',
				];
				continue;
			}
			if (!array_key_exists($entityTypeId, $current))
			{
				$skipped[] = [
					'entityTypeId' => $entityTypeId,
					'reason' => 'No such non-system relation exists.',
				];
				continue;
			}

			$result = $this->service->unbind($smartProcessId, $direction, $entityTypeId);
			if ($result->isSuccess())
			{
				$removed[] = ['entityTypeId' => $entityTypeId];
			}
			else
			{
				$rejected[] = [
					'entityTypeId' => $entityTypeId,
					'reason' => implode('; ', $result->getErrorMessages()),
				];
			}
		}

		return [
			'removed' => $removed,
			'skipped' => $skipped,
			'rejected' => $rejected,
		];
	}
}
