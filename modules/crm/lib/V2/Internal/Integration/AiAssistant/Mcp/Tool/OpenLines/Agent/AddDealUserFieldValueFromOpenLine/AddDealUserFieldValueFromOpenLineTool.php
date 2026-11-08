<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\OpenLines\Agent\AddDealUserFieldValueFromOpenLine;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\StringProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\OpenLineService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;
use CCrmOwnerType;

final class AddDealUserFieldValueFromOpenLineTool extends AbstractTool
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
			name: 'add_deal_user_field_value_from_open_line',
			description: <<<HEREDOC
				Allows you to add values to a deal field. To get a list of editable deal fields,
				use `list_deal_user_field_for_open_line` tool. This tool is intended for external Open Channel users.
			HEREDOC,
		))
			->setProperties([
				(new IntegerProperty(
					id: 'dealId',
					description: 'The identifier of the deal in which the value will be added',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new StringProperty(
					id: 'fieldId',
					description: <<<HEREDOC
						The ID of the field to set the value to.
						Use `list_deal_user_field_for_open_line` to get a list of available fields.
					HEREDOC,
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new StringProperty(
					id: 'fieldValue',
					description: <<<HEREDOC
						Required non-empty value to append to the deal custom field.
						Pass it as a string formatted for the field type returned by list_deal_user_field_for_open_line.
					HEREDOC,
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
			])
		;
	}

	/**
	 * @param AddDealUserFieldValueFromOpenLineToolDto $args
	 * @return ToolResult
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->openLineService->appendUserFieldValue(
			userId: $args->getUserId(),
			entityTypeId: CCrmOwnerType::Deal,
			entityId: $args->dealId,
			fieldId: $args->fieldId,
			fieldValue: $args->fieldValue,
		);

		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(message: 'The value was successfully added to the deal field.');
	}

	protected function getArgsDtoClass(): string
	{
		return AddDealUserFieldValueFromOpenLineToolDto::class;
	}
}
