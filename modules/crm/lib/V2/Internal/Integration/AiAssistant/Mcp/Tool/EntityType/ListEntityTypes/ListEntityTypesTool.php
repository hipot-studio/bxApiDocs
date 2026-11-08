<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\EntityType\ListEntityTypes;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\StringProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\EntityTypeService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class ListEntityTypesTool extends AbstractTool
{
	public function __construct(
		TracedLogger $tracedLogger,
		private readonly EntityTypeService $entityTypeService,
	)
	{
		parent::__construct($tracedLogger);
	}

	protected function getDefinition(): ToolDefinition
	{
		return (new ToolDefinition(
			name: 'list_entity_types',
			description: <<<HEREDOC
				Return CRM entity types that can be used in other MCP tools, including built-in CRM entities and smart processes.
				Call this first when you need a valid entityTypeId. entityTypeId means the exact CRM entity type identifier, not an item id.
			HEREDOC,
		))
			->setProperties([
				(new StringProperty(
					id: 'query',
					description: 'Optional search string used to filter CRM entity types by title.',
				))
					->setIsRequired(false)
					->setIsNullable(false)
				,
			])
		;
	}

	/**
	 * @param ListEntityTypesToolDto $args
	 * @return ToolResult
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->entityTypeService->listEntityTypes($args->getUserId(), $args->query);
		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(entityTypes: $result->get('entityTypes'));
	}

	protected function getArgsDtoClass(): string
	{
		return ListEntityTypesToolDto::class;
	}
}
