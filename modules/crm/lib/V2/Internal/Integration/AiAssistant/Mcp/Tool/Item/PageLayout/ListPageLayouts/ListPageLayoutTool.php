<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\PageLayout\ListPageLayouts;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\PageLayoutService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class ListPageLayoutTool extends AbstractTool
{
	public function __construct(
		TracedLogger $tracedLogger,
		private readonly PageLayoutService $pageLayoutService,
	)
	{
		parent::__construct($tracedLogger);
	}

	protected function getDefinition(): ToolDefinition
	{
		return (new ToolDefinition(
			name: 'list_page_layout',
			description: <<<HEREDOC
				List available CRM item card view layouts for one entity type and category.
				Use this to find pageLayoutId before updating a layout or switching the active card view.
			HEREDOC,
		))
			->setProperties([
				(new IntegerProperty(
					id: 'entityTypeId',
					description: 'Required exact CRM entity type identifier whose card view layouts should be listed. Get it from list_entity_types.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,

				(new IntegerProperty(
					id: 'categoryId',
					description: <<<HEREDOC
						Required category selector for card views.
						Pass a CRM pipeline id for deals and smart processes with pipelines enabled; get ids from list_categories.
						Pass null for smart processes without pipelines and for entity types without categories.
					HEREDOC,
				))
					->setIsRequired(false)
					->setIsNullable(true)
				,
			])
			->setIsAdditionalProperties(false)
		;
	}

	/**
	 * @param ListPageLayoutToolDto $args
	 * @return ToolResult
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->pageLayoutService->listPageLayouts($args->getUserId(), $args->entityTypeId, $args->categoryId);
		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(pageLayouts: $result->get('pageLayouts'));
	}

	protected function getArgsDtoClass(): string
	{
		return ListPageLayoutToolDto::class;
	}
}
