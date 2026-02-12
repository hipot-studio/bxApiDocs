<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools\Search;

final class DealCategoryListTool extends DealListTool
{
	public function getName(): string
	{
		return 'deal_category_list';
	}

	public function getDescription(): string
	{
		return <<<TEXT
Searches for CRM deals categories (funnels).
Use this function when you need to find all categories (funnels) for deals or find the funnel identifier by funnel name.
TEXT;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'name' => [
					'description' => 'CRM deal category (funnel) name (minimum 2 characters).',
					'type' => 'string',
					'minLength' => 2,
					'maxLength' => 100,
				],
			],
			'additionalProperties' => false,
		];
	}

	public function executeTool(int $userId, ...$args): string
	{
		$categories = $this
			->permissionService
			->getAvailableCategories($userId, $this->getEntityTypeId())
		;
		$name = $this->argumentExtractor->extractString($args, 'name');
		$categories = $this->metadataService->filterCategoriesByName($categories, $name);

		return $this->responseFormatter->formatCategoriesResponse($categories);
	}
}
