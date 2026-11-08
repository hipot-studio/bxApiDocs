<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\Category\CreateCategory;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\StringProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\CategoryService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class CreateCategoryTool extends AbstractTool
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
			name: 'create_category',
			description: 'Create a new CRM pipeline (category / sales funnel) for deals or a smart process with pipelines enabled.',
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
				,
				(new StringProperty(
					id: 'title',
					description: 'Required human-readable CRM pipeline name.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
			])
		;
	}

	/**
	 * @param CreateCategoryToolDto $args
	 * @return ToolResult
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->categoryService->createCategory(
			userId: $args->getUserId(),
			entityTypeId: $args->entityTypeId,
			title: $args->title,
		);
		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(category: $result->get('category'));
	}

	protected function getArgsDtoClass(): string
	{
		return CreateCategoryToolDto::class;
	}
}
