<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools\Search;

final class LeadStageListTool extends LeadListTool
{
	public function getName(): string
	{
		return 'lead_stage_list';
	}

	public function getDescription(): string
	{
		return <<<TEXT
Searches for CRM leads stages.
Use this function when you need to find all stages for leads or find the stage identifier by stage name.
TEXT;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'name' => [
					'description' => 'CRM lead stage name (minimum 2 characters).',
					'type' => 'string',
					'minLength' => 2,
					'maxLength' => 50,
				],
			],
			'additionalProperties' => false,
		];
	}

	public function executeTool(int $userId, ...$args): string
	{
		$stages = $this
			->permissionService
			->getAvailableStages($userId, $this->getEntityTypeId())
		;
		$name = $this->argumentExtractor->extractString($args, 'name');
		$stages = $this->metadataService->filterStagesByName($stages, $name);

		return $this->responseFormatter->formatStagesResponse($stages);
	}
}
