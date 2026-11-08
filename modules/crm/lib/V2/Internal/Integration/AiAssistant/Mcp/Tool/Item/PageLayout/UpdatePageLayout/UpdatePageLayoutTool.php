<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\PageLayout\UpdatePageLayout;

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

final class UpdatePageLayoutTool extends AbstractTool
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
				Use only ids returned by list_page_layout_elements_enum for the target entity type and category.
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
			name: 'update_page_layout',
			description: <<<HEREDOC
				Replace an existing CRM item card view layout.
				Use list_page_layout to get pageLayoutId and list_page_layout_elements_enum to choose valid element ids before calling this tool.
			HEREDOC,
		))
			->setIsAdditionalProperties(false)
			->setProperties([
				(new IntegerProperty(
					id: 'entityTypeId',
					description: 'Required exact CRM entity type identifier of the card view. Get it from list_entity_types.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,

				(new StringProperty(
					id: 'pageLayoutId',
					description: <<<HEREDOC
						Required id of the card view layout to update.
						Get it from list_page_layout for the same entityTypeId and category.
					HEREDOC,
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,

				(new ObjectProperty(
					id: 'pageLayout',
					description: 'Required complete replacement CRM item card view layout.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
					->setProperties([
						(new StringProperty(
							id: 'title',
							description: <<<HEREDOC
								Required updated name only when pageLayoutId identifies a custom page layout.
								Do not pass title for common or personal page layouts because system layouts cannot be renamed.
							HEREDOC,
						))
							->setIsRequired(false)
							->setIsNullable(false)
						,

						(new ArrayProperty(
							id: 'sections',
							description: <<<HEREDOC
								Required ordered list of sections in the replacement card view.
								Each section must contain an elements array.
							HEREDOC,
						))
							->setIsRequired(true)
							->setIsNullable(false)
							->setArrayItemProperty($sectionProperty)
						,
					])
				,
			])
		;
	}

	/**
	 * @param UpdatePageLayoutToolDto $args
	 * @return ToolResult
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->pageLayoutService->updatePageLayout(
			$args->getUserId(),
			$args->entityTypeId,
			$args->pageLayoutId,
			$args->pageLayout,
		);

		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(message: 'Page layout updated successfully');
	}

	protected function getArgsDtoClass(): string
	{
		return UpdatePageLayoutToolDto::class;
	}
}
