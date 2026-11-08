<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\Timeline\CreateComment;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\StringProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\TimelineService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class CreateCommentTool extends AbstractTool
{
	public function __construct(
		TracedLogger $tracedLogger,
		private readonly TimelineService $timelineService,
	)
	{
		parent::__construct($tracedLogger);
	}

	protected function getDefinition(): ToolDefinition
	{
		return (new ToolDefinition(
			name: 'create_comment',
			description: <<<HEREDOC
				Create a timeline comment on a specific CRM item.
				Use list_items first if you need to resolve the target item id.
			HEREDOC,
		))
			->setProperties([
				(new IntegerProperty(
					id: 'entityTypeId',
					description: 'Required exact CRM entity type identifier of the target record, not an item id. Get it from list_entity_types.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new IntegerProperty(
					id: 'itemId',
					description: <<<HEREDOC
						Required CRM item id of the target record within the selected entityTypeId.
						Get it from list_items or another trusted source.
					HEREDOC,
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new StringProperty(
					id: 'comment',
					description: 'Required plain-text comment body that will be added to the item timeline.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
			])
		;
	}

	/**
	 * @param CreateCommentToolDto $args
	 * @return ToolResult
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->timelineService->createComment(
			entityTypeId: $args->entityTypeId,
			entityId: $args->itemId,
			comment: $args->comment,
			userId: $args->getUserId(),
		);

		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(comment: $result->get('comment'));
	}

	protected function getArgsDtoClass(): string
	{
		return CreateCommentToolDto::class;
	}
}
