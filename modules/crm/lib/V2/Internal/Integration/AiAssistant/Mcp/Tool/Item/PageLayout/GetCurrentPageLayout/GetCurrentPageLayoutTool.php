<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\PageLayout\GetCurrentPageLayout;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Response\PageLayout;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\PageLayoutService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class GetCurrentPageLayoutTool extends AbstractTool
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
			name: 'get_current_page_layout',
			description: <<<HEREDOC
				Return the CRM item card view currently active for the current user, entity type, and category. Use this when you need to inspect the user's current field/section layout before creating, updating, or switching card views.
			HEREDOC,
		))
			->setProperties([
				(new IntegerProperty(
					id: 'entityTypeId',
					description: 'Required exact CRM entity type identifier of the card view. Get it from list_entity_types.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,

				(new IntegerProperty(
					id: 'categoryId',
					description: <<<HEREDOC
						Required category selector for the card view.
						Pass a CRM pipeline id for deals and smart processes with pipelines enabled; get ids from list_categories.
						Pass null for smart processes without pipelines and for entity types without categories.
					HEREDOC,
				))
					->setIsRequired(false)
					->setIsNullable(true)
				,
			])
		;
	}

	/**
	 * @param GetCurrentPageLayoutToolDto $args
	 * @return ToolResult<PageLayout>
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->pageLayoutService->getCurrentPageLayout($args->getUserId(), $args->entityTypeId, $args->categoryId);
		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(pageLayout: $result->get('pageLayout'));
	}

	protected function getArgsDtoClass(): string
	{
		return GetCurrentPageLayoutToolDto::class;
	}
}
