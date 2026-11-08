<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\UserField\CreateUserField;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\ArrayProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\BooleanProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\ObjectProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\StringProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Request\UserField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\UserFieldService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class CreateUserFieldTool extends AbstractTool
{
	public function __construct(
		TracedLogger $tracedLogger,
		private readonly UserFieldService $userFieldService,
	)
	{
		parent::__construct($tracedLogger);
	}

	protected function getDefinition(): ToolDefinition
	{
		$enumItem = (new ObjectProperty(
			id: '',
			description: 'One option of an enumeration custom field. Required only when userField.type is enumeration.',
		))
			->setProperties([
				(new StringProperty(
					id: 'value',
					description: 'Required visible option text stored in the enumeration field.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new BooleanProperty(
					id: 'default',
					description: 'Required flag that marks this option as selected by default for new CRM items.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new IntegerProperty(
					id: 'sort',
					description: 'Required numeric display order of this enumeration option. Lower values appear earlier.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
			])
		;

		$userField = (new ObjectProperty(
			id: 'userField',
			description: 'Definition of the custom CRM user field to create.',
		))
			->setIsRequired(true)
			->setIsNullable(false)
			->setProperties([
				(new StringProperty(
					id: 'title',
					description: 'Required human-readable field name shown in CRM item cards, lists, and filters.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new StringProperty(
					id: 'type',
					description: 'Required CRM user field type. Choose one of the values declared in this property enum.',
				))
					->setEnum(UserField::SUPPORTED_TYPES)
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new BooleanProperty(
					id: 'multiple',
					description: 'Required flag. Use true when the field must store multiple values; use false for a single value.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new ArrayProperty(
					id: 'enumList',
					description: <<<HEREDOC
						Required array when type is enumeration; otherwise omit this field.
						Contains all selectable options for the field.
					HEREDOC,
				))
					->setIsRequired(false)
					->setIsNullable(false)
					->setArrayItemProperty($enumItem)
				,
			])
		;

		return (new ToolDefinition(
			name: 'create_user_field',
			description: <<<HEREDOC
				Create a custom CRM user field for one CRM entity type and add it to the proper card view scope.
				The created field is added to the CRM item card view. Call list_entity_types first for entityTypeId; for enumeration fields, provide userField.enumList.
			HEREDOC,
		))
			->setProperties([
				(new IntegerProperty(
					id: 'entityTypeId',
					description: 'Required exact CRM entity type identifier where the custom field will be created. Get it from list_entity_types.',
				))
					->setIsRequired(true)
					->setIsNullable(false)
				,
				(new IntegerProperty(
					id: 'categoryId',
					description: <<<HEREDOC
						Required category selector for the card view scope.
						Pass a CRM pipeline id for deals and smart processes with pipelines enabled; get ids from list_categories.
						Pass null for smart processes without pipelines and for entity types without categories.
					HEREDOC,
				))
					->setIsRequired(false)
					->setIsNullable(true)
				,

				$userField,
			])
		;
	}

	/**
	 * @param CreateUserFieldToolDto $args
	 */
	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		$result = $this->userFieldService->createUserField(
			$args->getUserId(),
			$args->entityTypeId,
			$args->categoryId,
			$args->userField,
		);

		if (!$result->isSuccess())
		{
			return ToolResult::fail($result->getErrorCollection());
		}

		return ToolResult::success(fieldId: $result->get('fieldName'));
	}

	protected function getArgsDtoClass(): string
	{
		return CreateUserFieldToolDto::class;
	}
}
