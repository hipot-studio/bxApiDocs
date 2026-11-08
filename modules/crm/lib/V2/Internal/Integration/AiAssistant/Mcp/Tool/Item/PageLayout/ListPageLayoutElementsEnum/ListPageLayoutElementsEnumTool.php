<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\PageLayout\ListPageLayoutElementsEnum;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\PageLayoutService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class ListPageLayoutElementsEnumTool extends AbstractTool
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
			name: 'list_page_layout_elements_enum',
			description: <<<HEREDOC
				List CRM item card view elements that can be placed into pageLayout.sections[].elements[].
				Use these returned element ids when creating or updating a card view.
			HEREDOC,
		))
			->setProperties([
				(new IntegerProperty(
					id: 'entityTypeId',
					description: <<<HEREDOC
						Required exact CRM entity type identifier whose available card view elements should be listed.
						Get it from list_entity_types.
					HEREDOC,
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,

				(new IntegerProperty(
					id: 'categoryId',
					description: <<<HEREDOC
						Required category selector for available card view elements.
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
	 * @param ListPageLayoutElementsEnumToolDto $args
	 * @return ToolResult
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->pageLayoutService->listPageLayoutElementsEnum(
			$args->getUserId(),
			$args->entityTypeId,
			$args->categoryId,
		);

		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(elements: $result->get('elements'));
	}

	protected function getArgsDtoClass(): string
	{
		return ListPageLayoutElementsEnumToolDto::class;
	}
}
