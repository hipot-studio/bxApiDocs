<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\Timeline\CreateToDo;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\StringProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\TimelineService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class CreateToDoTool extends AbstractTool
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
		$format = \Bitrix\Main\Type\DateTime::getFormat();

		return (new ToolDefinition(
			name: 'create_to_do',
			description: <<<HEREDOC
				Creates a new to-do activity in the CRM and links it to a specified CRM item.
				The to-do is assigned to the current user.
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
					id: 'title',
					description: <<<HEREDOC
						Title of the to-do.
						A brief description of what needs to be done (e.g., "Call the client back", "Send a commercial proposal").
					HEREDOC,
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new StringProperty(
					id: 'description',
					description: <<<HEREDOC
						Detailed description of what needs to be done.
						May include instructions, conversation context, or important details about the CRM item.
					HEREDOC,
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new StringProperty(
					id: 'deadline',
					description: <<<HEREDOC
						Due date and time for the to-do.
						Format: '$format'. Used for deadline tracking.
					HEREDOC,
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
			])
		;
	}

	/**
	 * @param CreateToDoToolDto $args
	 * @return ToolResult
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->timelineService->createToDo(
			entityTypeId: $args->entityTypeId,
			entityId: $args->itemId,
			title: $args->title,
			description: $args->description,
			deadline: $args->deadline,
			userId: $args->getUserId(),
		);

		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(todo: $result->get('todo'));
	}

	protected function getArgsDtoClass(): string
	{
		return CreateToDoToolDto::class;
	}
}
