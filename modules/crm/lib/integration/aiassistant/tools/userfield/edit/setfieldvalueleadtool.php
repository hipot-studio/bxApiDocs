<?php

namespace Bitrix\Crm\Integration\AiAssistant\Tools\UserField\Edit;

use Bitrix\Main\Validation\Rule\PositiveNumber;

final class SetFieldValueLeadTool extends BaseSetFieldValueTool
{
	#[PositiveNumber(errorMessage: 'Lead ID must be a positive integer')]
	public int $leadId;

	public function getName(): string
	{
		return 'set_field_value_lead';
	}

	public function getDescription(): string
	{
		return <<<HEREDOC
			Fills in the value for lead's empty user field by given name and value.
			It adds the value to non-empty user field if it is multiple.
			Use it to set value for empty user field for lead entities in the CRM or add value to user fields that are multiple.
			For enum type of fields use codes of values as fieldValue.
			You can use tool list_editable_userfields_lead to get the list of available user fields for leads and their types.
			It returns success or error message.
		HEREDOC;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'leadId' => [
					'description' => 'CRM lead ID.',
					'type' => 'integer',
				],
				'fieldName' => [
					'description' => 'CRM lead user field name (maximum 255 characters).',
					'type' => 'string',
					'minLength' => 1,
					'maxLength' => 255,
				],
				'fieldValue' => [
					'description' => 'Value to set for the user field. For enum user field type it should be code of the value.',
					'type' => 'string',
					'minLength' => 1,
					'maxLength' => 255,
				],
			],
			'additionalProperties' => false,
			'required' => ['leadId', 'fieldName', 'fieldValue'],
		];
	}

	protected function innerExecute(): string
	{
		return $this->fillInUserField(
			$this->userId,
			$this->leadId,
			\CCrmOwnerType::Lead,
			$this->fieldName,
			$this->fieldValue,
		);
	}

	protected function parseInput(array $args): void
	{
		$this->leadId = (int)($args['leadId'] ?? 0);

		parent::parseInput($args);
	}
}
