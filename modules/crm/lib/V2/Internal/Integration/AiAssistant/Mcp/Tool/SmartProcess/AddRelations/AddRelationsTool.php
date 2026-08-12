<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess\AddRelations;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Factory\RelationsSchemaFactory;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Request\RelationEntityDto;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\SmartProcessRelationsService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class AddRelationsTool extends AbstractTool
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
			'Adds (binds) individual relations of a CRM smart process without touching the others.'
			. ' parentEntities are entities in whose card the smart process appears (each E is a PARENT of'
			. ' the smart process); childEntities are entities that become children of the smart process.'
			. ' Intent mapping: to show the smart process as a field in an entity card use childEntities;'
			. ' to show it as a tab with linked items use parentEntities with isChildrenListEnabled=true.'
			. ' Direction limits: parentEntities can not include Contact/Company; childEntities can not'
			. ' include Order (rejected automatically). Predefined (system) relations are never modified.'
			. ' Use get_smart_process_relations to inspect the current set;'
			. ' use configure_smart_process_relations to set the full list at once;'
			. ' use remove_smart_process_relations to unbind individual relations.'
		;

		return (new ToolDefinition(
			name: 'add_smart_process_relations',
			description: $description,
		))
			->setProperties([
				(new IntegerProperty(
					'smartProcessId',
					'ENTITY_TYPE_ID of the smart process. Use search_dynamic_type to find it.',
				))
					->setIsRequired(true)
				,
				RelationsSchemaFactory::entitiesArrayProperty(
					'parentEntities',
					'Entities to bind as PARENTS of the smart process (smart process shown in their card).',
				),
				RelationsSchemaFactory::entitiesArrayProperty(
					'childEntities',
					'Entities to bind as CHILDREN of the smart process.',
				),
			])
		;
	}

	protected function getArgsDtoClass(): string
	{
		return AddRelationsToolDto::class;
	}

	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		/** @var AddRelationsToolDto $args */
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
		] as $direction => $entities)
		{
			if ($entities === null)
			{
				continue;
			}

			$report[$direction] = $this->service->applyRelations(
				$smartProcessId,
				$direction,
				$this->toDesiredMap($entities),
				removeMissing: false,
			);
		}

		return ToolResult::success(
			smartProcessId: $smartProcessId,
			hasRejections: $this->service->hasRejections($report),
			result: $report,
		);
	}

	/**
	 * @param RelationEntityDto[] $entities
	 * @return array<int, bool> map entityTypeId => isChildrenListEnabled
	 */
	private function toDesiredMap(array $entities): array
	{
		$map = [];
		foreach ($entities as $entity)
		{
			$map[(int)$entity->entityTypeId] = $entity->isChildrenListEnabled;
		}

		return $map;
	}
}
