<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\Category\Stage\RenameStage;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\StringProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\StageService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class RenameStageTool extends AbstractTool
{
	public function __construct(
		TracedLogger $tracedLogger,
		private readonly StageService $stageService,
	)
	{
		parent::__construct($tracedLogger);
	}

	protected function getDefinition(): ToolDefinition
	{
		return (new ToolDefinition(
			name: 'rename_stage',
			description: 'Rename one existing CRM kanban stage (pipeline column) in a CRM entity type.',
		))
			->setProperties([
				(new IntegerProperty(
					id: 'entityTypeId',
					description: 'Required exact CRM entity type identifier with stages enabled, not an item id. Get it from list_entity_types.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new StringProperty(
					id: 'stageId',
					description: 'Required exact CRM kanban stage identifier (pipeline column id) returned by list_stages.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new StringProperty(
					id: 'title',
					description: 'Required new human-readable CRM kanban stage name.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
			])
		;
	}

	/**
	 * @param RenameStageToolDto $args
	 * @return ToolResult
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->stageService->renameStage(
			userId: $args->getUserId(),
			entityTypeId: $args->entityTypeId,
			stageId: $args->stageId,
			title: $args->title,
		);

		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(stage: $result->get('stage'));
	}

	protected function getArgsDtoClass(): string
	{
		return RenameStageToolDto::class;
	}
}
