<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools\DynamicType;

use Bitrix\Crm\Integration\AI\Function\Category\Stage\UpdateList;
use Bitrix\Crm\Integration\AiAssistant\Tools\BaseCrmTool;
use Bitrix\Main\Web\Json;

final class UpdateStagesTool extends BaseCrmTool
{
	public function getName(): string
	{
		return 'update_stages';
	}

	public function getDescription(): string
	{
		return 'Replaces all intermediate stages of a funnel in a dynamic type. '
			. 'Accepts the complete ordered list of stages that should exist between the start and the terminal (success/fail) stages. '
			. 'The tool performs a full sync: stages that already exist are renamed to match the provided list, '
			. 'new stages are appended, and stages absent from the list are deleted. Terminal stages (success and fail) '
			. 'are never affected. Requires entityTypeId and categoryId.';
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
					'description' => 'Identifier of the target funnel (category). Use 0 for the default category.',
					'type' => 'integer',
					'minimum' => 0,
				],
				'stages' => [
					'description' => "Full list of desired 'In Progress' stages in order. Cannot be empty.",
					'type' => 'array',
					'minItems' => 1,
					'items' => [
						'type' => 'object',
						'properties' => [
							'name' => [
								'description' => 'Name of the stage',
								'type' => 'string',
								'minLength' => 1,
							],
							'color' => [
								'description' => 'Color code in HEX format (e.g., "#FF0000"). Optional.',
								'type' => 'string',
							],
						],
						'required' => ['name'],
					],
				],
			],
			'additionalProperties' => false,
			'required' => ['entityTypeId', 'categoryId', 'stages'],
		];
	}

	protected function executeTool(int $userId, ...$args): string
	{
		$entityTypeId = (int)($args['entityTypeId'] ?? 0);
		$categoryId = (int)($args['categoryId'] ?? 0);
		$stages = $args['stages'] ?? [];

		if ($entityTypeId <= 0)
		{
			return Json::encode(['error' => 'entityTypeId must be a positive integer']);
		}

		if (empty($stages))
		{
			return Json::encode(['error' => 'Stages list cannot be empty']);
		}

		$operation = new UpdateList(currentUserId: $userId);
		$result = $operation->invoke(
			entityTypeId: $entityTypeId,
			categoryId: $categoryId,
			stages: $stages,
		);

		if (!$result->isSuccess())
		{
			return Json::encode([
				'error' => 'Failed to update stages',
				'details' => implode(', ', $result->getErrorMessages()),
			]);
		}

		return Json::encode([
			'success' => true,
			'message' => "Stages for category {$categoryId} updated successfully",
		]);
	}
}
