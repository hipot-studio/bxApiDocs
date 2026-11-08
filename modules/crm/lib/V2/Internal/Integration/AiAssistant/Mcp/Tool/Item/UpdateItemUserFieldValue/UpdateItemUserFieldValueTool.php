<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\UpdateItemUserFieldValue;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\StringProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\ItemService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class UpdateItemUserFieldValueTool extends AbstractTool
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
			name: 'update_item_user_field_value',
			description: <<<HEREDOC
				Append a value to a CRM item custom user field.
				Use list_entity_types to choose entityTypeId, list_items to choose itemId, and list_item_user_fields to choose fieldId before calling this tool.
			HEREDOC,
		))
			->setProperties([
				(new IntegerProperty(
					id: 'entityTypeId',
					description: 'Required exact CRM entity type identifier of the target item, not an item id. Get it from list_entity_types.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new IntegerProperty(
					id: 'itemId',
					description: 'Required CRM item id within the selected entityTypeId. Get it from list_items or from a previous CRM item operation.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new StringProperty(
					id: 'fieldId',
					description: <<<HEREDOC
						Required custom user field identifier to update, for example UF_CRM_*.
						Get the available field ids from list_item_user_fields for the same entityTypeId.
					HEREDOC,
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new StringProperty(
					id: 'fieldValue',
					description: <<<HEREDOC
						Required non-empty value to append to the custom field.
						Pass it as a string formatted for the field type returned by list_item_user_fields.
					HEREDOC,
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
			])
		;
	}

	/**
	 * @param UpdateItemUserFieldValueToolDto $args
	 * @return ToolResult
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->itemService->appendUserFieldValue(
			userId: $args->getUserId(),
			entityTypeId: $args->entityTypeId,
			itemId: $args->itemId,
			fieldId: $args->fieldId,
			fieldValue: $args->fieldValue,
		);

		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(message: 'Item updated successfully');
	}

	protected function getArgsDtoClass(): string
	{
		return UpdateItemUserFieldValueToolDto::class;
	}
}
