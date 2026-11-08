<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\OpenLines\Agent\AddLeadUserFieldValueFromOpenLine;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\StringProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\OpenLineService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;
use CCrmOwnerType;

final class AddLeadUserFieldValueFromOpenLineTool extends AbstractTool
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
			name: 'add_lead_user_field_value_from_open_line',
			description: <<<HEREDOC
				Allows you to add values to a lead field. To get a list of editable lead fields, use `list_lead_user_field_for_open_line` tool.
				This tool is intended for external Open Channel users.
			HEREDOC,
		))
			->setProperties([
				(new IntegerProperty(
					id: 'leadId',
					description: 'The identifier of the lead in which the value will be added',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new StringProperty(
					id: 'fieldId',
					description: <<<HEREDOC
						The ID of the field to set the value to.
						Use `list_lead_user_field_for_open_line` to get a list of available fields.
					HEREDOC,
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new StringProperty(
					id: 'fieldValue',
					description: <<<HEREDOC
						Required non-empty value to append to the lead custom field.
						Pass it as a string formatted for the field type returned by list_lead_user_field_for_open_line.
					HEREDOC,
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
			])
		;
	}

	/**
	 * @param AddLeadUserFieldValueFromOpenLineToolDto $args
	 * @return ToolResult
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->openLineService->appendUserFieldValue(
			userId: $args->getUserId(),
			entityTypeId: CCrmOwnerType::Lead,
			entityId: $args->leadId,
			fieldId: $args->fieldId,
			fieldValue: $args->fieldValue,
		);

		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(message: 'The value was successfully added to the lead field.');
	}

	protected function getArgsDtoClass(): string
	{
		return AddLeadUserFieldValueFromOpenLineToolDto::class;
	}
}
