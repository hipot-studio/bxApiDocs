<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools\DynamicType;

use Bitrix\Crm\Integration\AiAssistant\Tools\BaseCrmTool;
use Bitrix\Crm\Integration\AI\Function\UserField\UniversalCreateUserField;
use Bitrix\Main\Web\Json;

final class CreateUserFieldTool extends BaseCrmTool
{
	public function getName(): string
	{
		return 'create_custom_field';
	}

	public function getDescription(): string
	{
		return 'Creates a custom field for a dynamic type. '
			. 'Requires the entityTypeId of the dynamic type. '
			. 'Supports types: double, integer, string, date, datetime, enumeration, file, boolean.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'entityTypeId' => [
					'description' => 'Entity type identifier of the dynamic type. Use search_dynamic_type to find it.',
					'type' => 'integer',
					'minimum' => 1,
				],
				'categoryId' => [
					'description' => 'Identifier of the funnel (category) within the dynamic type. Use 0 for the default category.',
					'type' => 'integer',
					'minimum' => 0,
				],
				'label' => [
					'description' => 'Display name of the custom field to be created',
					'type' => 'string',
					'minLength' => 1,
				],
				'type' => [
					'description' => 'Data type of the custom field',
					'type' => 'string',
					'enum' => ['double', 'integer', 'string', 'date', 'datetime', 'enumeration', 'file', 'boolean'],
				],
				'isMultiple' => [
					'description' => 'Whether the field accepts multiple values. Always false for boolean fields.',
					'type' => 'boolean',
				],
				'enumerationList' => [
					'description' => 'List of selectable options. Required only when type is enumeration.',
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'value' => [
								'description' => 'Display name of a selectable option',
								'type' => 'string',
							],
						],
						'required' => ['value'],
					],
				],
			],
			'additionalProperties' => false,
			'required' => ['entityTypeId', 'categoryId', 'label', 'type', 'isMultiple'],
		];
	}

	protected function executeTool(int $userId, ...$args): string
	{
		$operation = new UniversalCreateUserField(currentUserId: $userId);
		$result = $operation->invoke(
			entityTypeId: (int)$args['entityTypeId'],
			categoryId: (int)$args['categoryId'],
			label: (string)$args['label'],
			type: (string)$args['type'],
			isMultiple: (bool)$args['isMultiple'],
			enumerationList: $args['enumerationList'] ?? null,
		);

		if ($result->isSuccess())
		{
			return Json::encode([
				'success' => true,
				'message' => "Custom field '{$args['label']}' created successfully",
			]);
		}

		return Json::encode([
			'error' => 'Failed to create custom field',
			'details' => implode(', ', $result->getErrorMessages()),
		]);
	}
}
