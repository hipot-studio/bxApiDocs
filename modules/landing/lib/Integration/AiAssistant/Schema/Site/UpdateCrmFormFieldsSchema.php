<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Schema\Site;

use Bitrix\Landing\Integration\AiAssistant\Schema\BaseSchema;

class UpdateCrmFormFieldsSchema extends BaseSchema
{
	protected function getProperties(): array
	{
		return [
			'formId' => [
				'type' => 'integer',
				'description' => 'CRM web form identifier to update. This changes the CRM form itself everywhere it is embedded.',
				'minimum' => 1,
			],
			'pageId' => [
				'type' => ['integer', 'null'],
				'description' => 'Trusted landing page identifier for realtime refresh of the selected CRM-form block.',
				'minimum' => 1,
			],
			'blockId' => [
				'type' => ['integer', 'null'],
				'description' => 'Trusted CRM-form landing block identifier for realtime refresh. Must belong to pageId and contain formId.',
				'minimum' => 1,
			],
			'expectedFieldsHash' => [
				'type' => 'string',
				'description' => 'Fresh fieldsHash returned by get_crm_form_fields. Used for optimistic conflict protection.',
			],
			'confirmSynchronization' => [
				'type' => 'boolean',
				'description' => 'Set true only after the user confirms CRM synchronization changes returned by a previous response.',
			],
			'operations' => [
				'type' => 'array',
				'minItems' => 1,
				'description' => 'Safe field operations to apply to the CRM form fields list.',
				'items' => [
					'type' => 'object',
					'properties' => [
						'type' => [
							'type' => 'string',
							'enum' => [
								'add_existing_field',
								'add_layout_field',
								'remove_field',
								'update_field',
								'move_field',
							],
						],
						'fieldName' => [
							'type' => ['string', 'null'],
							'description' => 'CRM field technical name for add_existing_field or field lookup fallback.',
						],
						'fieldId' => [
							'type' => ['integer', 'null'],
							'description' => 'Existing CRM form field row ID for remove, update, or move operations.',
							'minimum' => 1,
						],
						'layoutType' => [
							'type' => ['string', 'null'],
							'enum' => ['section', 'page', 'hr', 'br', null],
							'description' => 'Layout element type for add_layout_field.',
						],
						'position' => [
							'type' => ['integer', 'null'],
							'description' => 'One-based target position in the form fields list.',
							'minimum' => 1,
						],
						'patch' => [
							'type' => ['object', 'null'],
							'description' => 'Allowlisted field property changes.',
							'properties' => [
								'label' => ['type' => 'string'],
								'required' => ['type' => 'boolean'],
								'multiple' => ['type' => 'boolean'],
								'placeholder' => ['type' => 'string'],
								'hint' => ['type' => 'string'],
								'hintOnFocus' => ['type' => 'boolean'],
								'autocomplete' => ['type' => 'boolean'],
								'value' => ['type' => ['string', 'number', 'boolean', 'null']],
								'valueType' => ['type' => 'string'],
								'items' => [
									'type' => 'array',
									'items' => [
										'type' => 'object',
										'properties' => [
											'value' => ['type' => ['string', 'number', 'boolean']],
											'label' => ['type' => 'string'],
											'selected' => ['type' => 'boolean'],
											'disabled' => ['type' => 'boolean'],
										],
										'required' => ['value', 'label'],
										'additionalProperties' => false,
									],
								],
							],
							'additionalProperties' => false,
						],
					],
					'required' => ['type'],
					'additionalProperties' => false,
				],
			],
		];
	}

	protected function getRequiredFields(): array
	{
		return ['formId', 'expectedFieldsHash', 'operations'];
	}
}
