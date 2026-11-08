<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\OpenLines\Agent\CreateLeadCommentFromOpenLine;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\StringProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\OpenLineService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;
use CCrmOwnerType;

final class CreateLeadCommentFromOpenLineTool extends AbstractTool
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
		return (new ToolDefinition(
			name: 'create_lead_comment_from_open_line',
			description: <<<HEREDOC
				Allows you to create a comment in a CRM lead.
				This tool is intended for external Open Channel users.
			HEREDOC,
		))
			->setProperties([
				(new IntegerProperty(
					id: 'leadId',
					description: 'The lead ID for which the comment is being created.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new StringProperty(
					id: 'comment',
					description: 'Comment text.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
			])
		;
	}

	/**
	 * @param CreateLeadCommentFromOpenLineToolDto $args
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->openLineService->createComment(
			userId: $args->getUserId(),
			entityTypeId: CCrmOwnerType::Lead,
			entityId: $args->leadId,
			comment: $args->comment,
		);

		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(message: 'A comment was successfully added to the lead.');
	}

	protected function getArgsDtoClass(): string
	{
		return CreateLeadCommentFromOpenLineToolDto::class;
	}
}
