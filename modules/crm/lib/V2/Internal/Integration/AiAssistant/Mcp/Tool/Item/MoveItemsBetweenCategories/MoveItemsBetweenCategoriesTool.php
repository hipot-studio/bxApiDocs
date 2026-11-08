<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\MoveItemsBetweenCategories;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\ItemService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class MoveItemsBetweenCategoriesTool extends AbstractTool
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
			name: 'move_items_between_categories',
			description: <<<HEREDOC
				Move accessible CRM items from one CRM pipeline (category / sales funnel) to another within deals or a smart process with pipelines enabled.
				The current implementation processes at most 100 items from the source pipeline per call.
			HEREDOC,
		))
			->setProperties([
				(new IntegerProperty(
					id: 'entityTypeId',
					description: 'Required exact CRM entity type identifier for deals or a smart process with pipelines enabled, not an item id. Get it from list_entity_types.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new IntegerProperty(
					id: 'sourceCategoryId',
					description: <<<HEREDOC
						Required source CRM pipeline id (category / sales funnel id).
						Get it from list_categories for the same entityTypeId.
						All accessible items from this pipeline are candidates to be moved.
					HEREDOC,
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new IntegerProperty(
					id: 'targetCategoryId',
					description: <<<HEREDOC
						Required target CRM pipeline id (category / sales funnel id) within the same entity type.
						Get it from list_categories for the same entityTypeId.
					HEREDOC,
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
			])
		;
	}

	/**
	 * @param MoveItemsBetweenCategoriesToolDto $args
	 * @return ToolResult
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->itemService->moveItemsBetweenCategories(
			userId: $args->getUserId(),
			entityTypeId: $args->entityTypeId,
			sourceCategoryId: $args->sourceCategoryId,
			targetCategoryId: $args->targetCategoryId,
		);

		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(updatedCount: $result->get('updatedCount'), leftCount: $result->get('leftCount'));
	}

	protected function getArgsDtoClass(): string
	{
		return MoveItemsBetweenCategoriesToolDto::class;
	}
}
