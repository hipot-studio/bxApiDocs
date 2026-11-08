<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\GetItemsAmountByStage;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\StringProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\ItemService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class GetItemsAmountByStageTool extends AbstractTool
{
	public function __construct(
		TracedLogger $tracedLogger,
		private readonly ItemService $itemService,
	)
	{
		parent::__construct($tracedLogger);
	}

	protected function getDefinition(): ToolDefinition
	{
		return (new ToolDefinition(
			name: 'get_items_amount_by_stage',
			description: <<<HEREDOC
				Return the total opportunity amount for CRM items in one CRM kanban stage (pipeline column), optionally narrowed by item close date range.
				stageId is mandatory because permissions are checked against that exact kanban stage.
			HEREDOC,
		))
			->setProperties([
				(new IntegerProperty(
					id: 'entityTypeId',
					description: 'Required exact CRM entity type identifier, not an item id. Get it from list_entity_types.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new StringProperty(
					id: 'stageId',
					description: <<<HEREDOC
						Required exact CRM kanban stage identifier (pipeline column id) used both for filtering and for kanban sum permission checks.
						Get it from list_stages.
					HEREDOC,
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new StringProperty(
					id: 'closedDateFrom',
					description: <<<HEREDOC
						Optional lower bound for the CRM item close date in YYYY-MM-DD format, not the creation date or update date.
						Ignored if the entity has no close date field.
					HEREDOC,
				))
					->setIsRequired(false)
					->setIsNullable(false)
				,
				(new StringProperty(
					id: 'closedDateTo',
					description: <<<HEREDOC
						Optional upper bound for the CRM item close date in YYYY-MM-DD format, not the creation date or update date.
						Ignored if the entity has no close date field.
					HEREDOC,
				))
					->setIsRequired(false)
					->setIsNullable(false)
				,
			])
		;
	}

	/**
	 * @param GetItemsAmountByStageToolDto $args
	 * @return ToolResult
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$filter = $this->itemService->buildFilter(
			entityTypeId: $args->entityTypeId,
			stageId: $args->stageId,
			closedDateFrom: $args->closedDateFrom,
			closedDateTo: $args->closedDateTo,
		);

		$result = $this->itemService->getItemsAmount(
			userId: $args->getUserId(),
			entityTypeId: $args->entityTypeId,
			stageId: $args->stageId,
			filter: $filter,
		);

		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(amount: $result->get('amount'));
	}

	protected function getArgsDtoClass(): string
	{
		return GetItemsAmountByStageToolDto::class;
	}
}
