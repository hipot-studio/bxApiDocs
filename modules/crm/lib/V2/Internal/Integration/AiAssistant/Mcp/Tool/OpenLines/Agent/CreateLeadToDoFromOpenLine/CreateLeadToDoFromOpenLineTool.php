<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\OpenLines\Agent\CreateLeadToDoFromOpenLine;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\StringProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\OpenLineService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;
use Bitrix\Main\Type\DateTime;
use CCrmOwnerType;

final class CreateLeadToDoFromOpenLineTool extends AbstractTool
{
	public function __construct(
		TracedLogger $tracedLogger,
		private readonly OpenLineService $openLineService,
	)
	{
		parent::__construct($tracedLogger);
	}

	protected function getDefinition(): ToolDefinition
	{
		$format = DateTime::getFormat();

		return (new ToolDefinition(
			name: 'create_lead_to_do_from_open_line',
			description: <<<HEREDOC
				Creates a new to-do activity in the CRM and links it to a specified lead.
				Used for planning and assigning tasks to a manager.

				This is a specialized version of the tool that is required for creating to-do items from open lines.
				It is used only if it is explicitly stated that the target user is an external user of open lines.

				The to-do is assigned to the current responsible manager of the lead.
			HEREDOC,
		))
			->setProperties([
				(new IntegerProperty(
					id: 'leadId',
					description: 'ID of the CRM lead to which this to-do will be attached.',
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
						May include instructions, conversation context, or important details about the lead.
					HEREDOC,
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new StringProperty(
					id: 'deadline',
					description: "Due date and time for the to-do. Format: '$format'. Used for deadline tracking.",
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
			])
		;
	}

	/**
	 * @param CreateLeadToDoFromOpenLineToolDto $args
	 * @return ToolResult
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->openLineService->createToDo(
			userId: $args->getUserId(),
			entityTypeId: CCrmOwnerType::Lead,
			entityId: $args->leadId,
			title: $args->title,
			description: $args->description,
			deadline: $args->deadline,
		);
		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(message: 'To-do has been successfully created and attached from open lines to the lead');
	}

	protected function getArgsDtoClass(): string
	{
		return CreateLeadToDoFromOpenLineToolDto::class;
	}
}
