<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\MoveItemsBetweenStages;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\StringProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\ItemService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class MoveItemsBetweenStagesTool extends AbstractTool
{
	public function __construct(
		TracedLogger $tracedLogger,
		private readonly ItemService $itemService,
	)
	{
		parent::__construct($tracedLogger);
	}

	protected function getDefinition(): ToolDefinition
	{
		return (new ToolDefinition(
			name: 'move_items_between_stages',
			description: <<<HEREDOC
				Move accessible CRM items from one CRM kanban stage (pipeline column) to another within the same entity type.
				The current implementation processes at most 100 items from the source kanban stage per call.
			HEREDOC,
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
					id: 'sourceStageId',
					description: <<<HEREDOC
						Required source CRM kanban stage identifier (pipeline column id).
						Get it from list_stages for the same entityTypeId and relevant stage scope.
						All accessible items in this kanban stage are candidates to be moved.
					HEREDOC,
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new StringProperty(
					id: 'targetStageId',
					description: <<<HEREDOC
						Required target CRM kanban stage identifier (pipeline column id).
						Get it from list_stages for the same entityTypeId and relevant stage scope.
						It must belong to the same entity type and be a valid existing stage.
					HEREDOC,
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
			])
		;
	}

	/**
	 * @param MoveItemsBetweenStagesToolDto $args
	 * @return ToolResult
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->itemService->moveItemsBetweenStages(
			userId: $args->getUserId(),
			entityTypeId: $args->entityTypeId,
			sourceStageId: $args->sourceStageId,
			targetStageId: $args->targetStageId,
		);

		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(updatedCount: $result->get('updatedCount'), leftCount: $result->get('leftCount'));
	}

	protected function getArgsDtoClass(): string
	{
		return MoveItemsBetweenStagesToolDto::class;
	}
}
