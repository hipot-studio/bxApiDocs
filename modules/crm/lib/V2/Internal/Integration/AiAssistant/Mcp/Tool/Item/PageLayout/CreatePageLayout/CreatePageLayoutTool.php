<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\PageLayout\CreatePageLayout;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\ArrayProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\ObjectProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\StringProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\PageLayoutService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class CreatePageLayoutTool extends AbstractTool
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
		$elementProperty = (new ObjectProperty(
			id: 'element',
			description: <<<HEREDOC
				One CRM item card view element.
				Use only ids returned by list_page_layout_elements_enum for the same entityTypeId and categoryId.
			HEREDOC,
		))
			->setProperties([
				(new StringProperty(
					id: 'id',
					description: 'Required element id to place in a section. Get available ids by calling list_page_layout_elements_enum.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
			])
		;

		$sectionProperty = (new ObjectProperty(
			id: 'section',
			description: 'One section of the CRM item card view.',
		))
			->setProperties([
				(new StringProperty(
					id: 'title',
					description: 'Required visible section title in the CRM item card.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,

				(new ArrayProperty(
					id: 'elements',
					description: 'Required ordered list of CRM item card elements in this section.',
				))
					->setArrayItemProperty($elementProperty)
					->setIsRequired(true)
					->setIsNullable(false)
				,
			])
		;

		return (new ToolDefinition(
			name: 'create_page_layout',
			description: <<<HEREDOC
				Create a new personal CRM item card view for the current user.
				Build pageLayout from element ids returned by list_page_layout_elements_enum, then use switch_current_page_layout or switch_users_page_layout if the created view must become active.
			HEREDOC,
		))
			->setProperties([
				(new IntegerProperty(
					id: 'entityTypeId',
					description: 'Required exact CRM entity type identifier for the card view. Get it from list_entity_types.',
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

				(new ObjectProperty(
					id: 'pageLayout',
					description: 'Required complete CRM item card view layout to create.',
				))
					->setProperties([
						(new StringProperty(
							id: 'title',
							description: 'Required name of the card view layout shown to the user.',
						))
							->setIsRequired(true)
							->setIsNullable(false)
						,

						(new ArrayProperty(
							id: 'sections',
							description: <<<HEREDOC
								Required ordered list of sections in the card view.
								Each section must contain an elements array.
							HEREDOC,
						))
							->setIsRequired(true)
							->setIsNullable(false)
							->setArrayItemProperty($sectionProperty)
						,
					])
					->setIsRequired(true)
					->setIsNullable(false)
				,
			])
		;
	}

	/**
	 * @param CreatePageLayoutToolDto $args
	 * @return ToolResult
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->pageLayoutService->createCustomPageLayout(
			$args->getUserId(),
			$args->entityTypeId,
			$args->categoryId,
			$args->pageLayout,
		);

		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(pageLayout: $result->get('pageLayout'));
	}

	protected function getArgsDtoClass(): string
	{
		return CreatePageLayoutToolDto::class;
	}
}
