<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\Category\DeleteCategory;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\CategoryService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class DeleteCategoryTool extends AbstractTool
{
	public function __construct(
		TracedLogger $tracedLogger,
		private readonly CategoryService $categoryService,
	)
	{
		parent::__construct($tracedLogger);
	}

	protected function getDefinition(): ToolDefinition
	{
		return (new ToolDefinition(
			name: 'delete_category',
			description: 'Delete one CRM pipeline (category / sales funnel) from deals or a smart process with pipelines enabled.',
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
					id: 'categoryId',
					description: 'Required CRM pipeline id (category / sales funnel id) to delete. Get it from list_categories.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
			])
		;
	}

	/**
	 * @param DeleteCategoryToolDto $args
	 * @return ToolResult
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->categoryService->deleteCategory(
			userId: $args->getUserId(),
			entityTypeId: $args->entityTypeId,
			categoryId: $args->categoryId,
		);

		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(message: 'Category deleted successfully.');
	}

	protected function getArgsDtoClass(): string
	{
		return DeleteCategoryToolDto::class;
	}
}
