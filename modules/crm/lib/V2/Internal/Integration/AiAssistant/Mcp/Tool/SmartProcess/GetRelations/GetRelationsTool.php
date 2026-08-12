<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess\GetRelations;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\SmartProcessRelationsService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class GetRelationsTool extends AbstractTool
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
			'Returns the current non-system relations of a CRM smart process.'
			. ' parentEntities are entities in whose card the smart process appears (each is a PARENT of'
			. ' the smart process); childEntities are entities that are children of the smart process.'
			. ' The element format is identical to the input of the write tools, so the result can be edited'
			. ' and sent to configure_smart_process_relations. Use this before'
			. ' configure_smart_process_relations to see the current set.'
		;

		return (new ToolDefinition(
			name: 'get_smart_process_relations',
			description: $description,
		))
			->setProperties([
				(new IntegerProperty(
					'smartProcessId',
					'ENTITY_TYPE_ID of the smart process. Use search_dynamic_type to find it.',
				))
					->setIsRequired(true)
				,
			])
		;
	}

	protected function getArgsDtoClass(): string
	{
		return GetRelationsToolDto::class;
	}

	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		/** @var GetRelationsToolDto $args */
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

		return ToolResult::success(
			smartProcessId: $smartProcessId,
			parentEntities: $this->format(
				$this->service->currentRelations($smartProcessId, SmartProcessRelationsService::DIRECTION_PARENT),
			),
			childEntities: $this->format(
				$this->service->currentRelations($smartProcessId, SmartProcessRelationsService::DIRECTION_CHILD),
			),
		);
	}

	/**
	 * @param array<int, bool> $relations
	 */
	private function format(array $relations): array
	{
		$result = [];
		foreach ($relations as $entityTypeId => $isChildrenListEnabled)
		{
			$result[] = [
				'entityTypeId' => $entityTypeId,
				'isChildrenListEnabled' => $isChildrenListEnabled,
			];
		}

		return $result;
	}
}
