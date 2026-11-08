<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\Category\ListCategories;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\CategoryService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class ListCategoriesTool extends AbstractTool
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
			name: 'list_categories',
			description: <<<HEREDOC
				Return CRM pipelines (categories / sales funnels) for deals or a smart process with pipelines enabled.
				Call this before creating items in a specific pipeline, moving items between pipelines, or editing pipeline names.
			HEREDOC,
		))
			->setProperties([
				(new IntegerProperty(
					id: 'entityTypeId',
					description: <<<HEREDOC
						Required exact CRM entity type identifier for deals or a smart process with pipelines enabled, not an item id.
						Get it from list_entity_types.
					HEREDOC,
				))
					->setIsRequired(true)
					->setIsNullable(false)
			])
		;
	}

	/**
	 * @param ListCategoriesToolDto $args
	 * @return ToolResult
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->categoryService->listCategories($args->getUserId(), $args->entityTypeId);
		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(categories: $result->get('categories'));
	}

	protected function getArgsDtoClass(): string
	{
		return ListCategoriesToolDto::class;
	}
}
