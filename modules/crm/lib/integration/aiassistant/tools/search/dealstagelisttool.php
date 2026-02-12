<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools\Search;

final class DealStageListTool extends DealListTool
{
	public function getName(): string
	{
		return 'deal_stage_list';
	}

	public function getDescription(): string
	{
		return <<<TEXT
Searches for CRM deal stages for deal in the specified category (funnel).
Use this function when you need to find all stages for deal in the specified category (funnel) or find the stage identifier by stage name in the specified category (funnel).
TEXT;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'categoryId' => [
					'description' => 'Deal category (funnel) identifier.',
					'type' => 'integer',
					'minimum' => 0,
					'maximum' => 10000,
					'default' => 0,
				],
				'name' => [
					'description' => 'CRM deal stage name (minimum 2 characters).',
					'type' => 'string',
					'minLength' => 2,
					'maxLength' => 50,
				],
			],
			'additionalProperties' => false,
			'required' => ['categoryId'],
		];
	}

	public function executeTool(int $userId, ...$args): string
	{
		$categoryId = $this->argumentExtractor->extractCategoryId($args);
		$stages = $this
			->permissionService
			->getAvailableStages($userId, $this->getEntityTypeId(), $categoryId)
		;
		$name = $this->argumentExtractor->extractString($args, 'name');
		$stages = $this->metadataService->filterStagesByName($stages, $name);

		return $this->responseFormatter->formatStagesResponse($stages);
	}
}
