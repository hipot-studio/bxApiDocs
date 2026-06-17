<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools;

class CategoryUpdateStagesList extends BaseCrmTool
{
	public function getName(): string
	{
		return 'update_funnel_stages';
	}

	public function getDescription(): string
	{
		return "Updates the 'In Progress' stages of an existing CRM funnel identified by `categoryId`."
			. " Use this function to add, remove, rename, recolor, or reorder the custom (non-final) stages of a funnel — the order of items in `stages` defines the resulting order of In Progress stages."
			. " This is a positional replace-all for the In Progress portion of the funnel, NOT a patch: existing In Progress stages are matched to incoming items strictly by position (1st existing ↔ 1st provided, 2nd ↔ 2nd, and so on)"
			. " For a safe call you MUST pass the full current list of In Progress stages — omitting a stage will silently delete it, so partial / patch-style updates are not supported."
			. " Final stages (e.g. 'Won' / 'Lost' and other system / non-process stages) are never modified by this tool: they cannot be renamed, recolored, reordered, added, or removed via this function. Recoloring or renaming final stages is NOT supported here.";
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'categoryId' => [
					'description' => 'Identifier of the target funnel',
					'type' => 'number',
				],
				'stages' => [
					'description' => 'Array of stage definitions (cannot be empty)',
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'name' => [
								'description' => 'Name of the stage',
								'type' => 'string',
							],
							'color' => [
								'description' => 'Color code in HEX format (e.g., "#FF0000")',
								'type' => 'string',
							],
						],
						'required' => ['name'],
					],
				],
			],
			'additionalProperties' => false,
			'required' => ['categoryId', 'stages'],
		];
	}

	public function executeTool(int $userId, ...$args): string
	{
		$operation = new \Bitrix\Crm\Integration\AI\Function\Deal\Category\UpdateStageList(currentUserId: $userId);
		$result = $operation->invoke(categoryId: $args['categoryId'], stages: $args['stages']);

		return $result->isSuccess()
			? "Stages list for category '{$args['categoryId']}' successfully updated"
			: "Error updating stage list for category: " . implode(", ", $result->getErrorMessages());
	}
}
