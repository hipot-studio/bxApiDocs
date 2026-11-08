<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\ListItems;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\StringProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\ItemService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class ListItemsTool extends AbstractTool
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
			name: 'list_items',
			description: <<<HEREDOC
				Return up to 50 CRM items for one CRM entity type.
				Use this tool to resolve item ids before update or timeline actions, and combine the optional filters to narrow the result set by pipeline, kanban stage, or close date.
			HEREDOC,
		))
			->setProperties([
				(new IntegerProperty(
					id: 'entityTypeId',
					description: <<<HEREDOC
						Required exact CRM entity type identifier, not an item id.
						Get it from list_entity_types and keep it consistent across all other item, pipeline, and stage tools.
					HEREDOC,
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new StringProperty(
					id: 'query',
					description: <<<HEREDOC
						Optional search string used to find CRM items within the selected entity type, typically by item title or other searchable text.
					HEREDOC,
				))
					->setIsRequired(false)
					->setIsNullable(false)
				,
				(new IntegerProperty(
					id: 'categoryId',
					description: <<<HEREDOC
						Required category selector and optional CRM pipeline filter.
						Pass a CRM pipeline id for deals and smart processes with pipelines enabled; get ids from list_categories.
						Pass null for smart processes without pipelines and for entity types without categories.
					HEREDOC,
				))
					->setIsRequired(false)
					->setIsNullable(true)
				,
				(new StringProperty(
					id: 'stageId',
					description: <<<HEREDOC
						Optional exact CRM kanban stage identifier filter (pipeline column id). Use stage ids returned by list_stages.
					HEREDOC,
				))
					->setIsRequired(false)
					->setIsNullable(false)
				,
				(new StringProperty(
					id: 'stageSemantic',
					description: <<<HEREDOC
						Optional semantic group of CRM kanban stages: P = in-progress stage, S = successful final stage, F = failed final stage.
					HEREDOC,
				))
					->setIsRequired(false)
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
				(new IntegerProperty(
					id: 'limit',
					description: 'Optional number of items to return. Must be between 1 and 50; omit it to use the default limit of 50.',
				))
					->setIsRequired(false)
					->setIsNullable(false)
				,
			])
		;
	}

	/**
	 * @param ListItemsToolDto $args
	 * @return ToolResult
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$filter = $this->itemService->buildFilter(
			entityTypeId: $args->entityTypeId,
			categoryId: $args->categoryId,
			stageId: $args->stageId,
			stageSemantic: $args->stageSemantic,
			closedDateFrom: $args->closedDateFrom,
			closedDateTo: $args->closedDateTo,
		);

		$result = $this->itemService->listItems(
			userId: $args->getUserId(),
			entityTypeId: $args->entityTypeId,
			query: $args->query,
			filter: $filter,
			limit: $args->limit,
			stageId: $args->stageId,
		);

		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(items: $result->get('items'));
	}

	protected function getArgsDtoClass(): string
	{
		return ListItemsToolDto::class;
	}
}
