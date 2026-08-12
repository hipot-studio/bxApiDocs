<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools\DynamicType;

use Bitrix\Crm\Integration\AiAssistant\Tools\BaseCrmTool;
use Bitrix\Crm\Integration\AI\Function\EntityDetailsCardView\Create;
use Bitrix\Main\Web\Json;

final class CreateCardViewTool extends BaseCrmTool
{
	public function getName(): string
	{
		return 'create_card_view';
	}

	public function getDescription(): string
	{
		return 'Configures the entity detail card for a dynamic type. '
			. 'Creates a new card configuration with the specified sections and fields. '
			. 'Each section contains elements (fields) identified by their field names. '
			. 'Call list_dynamic_type_fields first to obtain valid field names for the target dynamic type. '
			. 'The configuration is applied as a common scope to all users.';
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
					'description' => 'Identifier of the funnel (category). Use 0 for the default category.',
					'type' => 'integer',
					'minimum' => 0,
				],
				'title' => [
					'description' => 'Name of the card configuration',
					'type' => 'string',
					'minLength' => 1,
				],
				'sections' => [
					'description' => 'List of card sections. Each section groups related fields together.',
					'type' => 'array',
					'minItems' => 1,
					'items' => [
						'type' => 'object',
						'properties' => [
							'name' => [
								'description' => 'Unique internal name of the section (e.g., "main_info", "contacts")',
								'type' => 'string',
								'minLength' => 1,
							],
							'title' => [
								'description' => 'Display title of the section',
								'type' => 'string',
								'minLength' => 1,
							],
							'elements' => [
								'description' => 'List of fields in this section',
								'type' => 'array',
								'minItems' => 1,
								'items' => [
									'type' => 'object',
									'properties' => [
										'name' => [
											'description' => 'Field name as returned by list_dynamic_type_fields (e.g., "TITLE", "UF_CRM_123")',
											'type' => 'string',
											'minLength' => 1,
										],
									],
									'required' => ['name'],
								],
							],
						],
						'required' => ['name', 'title', 'elements'],
					],
				],
			],
			'additionalProperties' => false,
			'required' => ['entityTypeId', 'categoryId', 'title', 'sections'],
		];
	}

	protected function executeTool(int $userId, ...$args): string
	{
		$entityTypeId = (int)($args['entityTypeId'] ?? 0);
		$categoryId = (int)($args['categoryId'] ?? 0);
		$title = trim((string)($args['title'] ?? ''));
		$sections = $args['sections'] ?? [];

		if ($entityTypeId <= 0)
		{
			return Json::encode(['error' => 'entityTypeId must be a positive integer']);
		}

		if ($title === '')
		{
			return Json::encode(['error' => 'Title is required and cannot be empty']);
		}

		if (empty($sections))
		{
			return Json::encode(['error' => 'Sections list cannot be empty']);
		}

		$operation = new Create(currentUserId: $userId);
		$result = $operation->invoke(
			entityTypeId: $entityTypeId,
			categoryId: $categoryId,
			title: $title,
			sections: $sections,
			common: true,
			forceSetToUsers: true,
		);

		if ($result->isSuccess())
		{
			return Json::encode([
				'success' => true,
				'message' => "Card configuration '{$title}' created successfully",
			]);
		}

		return Json::encode([
			'error' => 'Failed to configure card',
			'details' => implode(', ', $result->getErrorMessages()),
		]);
	}
}
