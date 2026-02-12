<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools\Search\Helper;

use Bitrix\Crm\Category\Entity\Category;
use Bitrix\Crm\EO_Status;
use Bitrix\Crm\Service\Container;

final class EntityMetadataService
{
	private const DEFAULT_CACHE_TTL = 3600; // 1 hour

	/**
	 * @param EO_Status[] $stages
	 * @param string $name
	 *
	 * @return EO_Status[]
	 */
	public function filterStagesByName(array $stages, string $name): array
	{
		if (empty($name))
		{
			return $stages;
		}

		return array_filter(
			$stages,
			static fn(EO_Status $stage) => mb_stripos($stage->getName(), $name) !== false
		);
	}

	/**
	 * @param Category[] $categories
	 * @param string $name
	 *
	 * @return Category[]
	 */
	public function filterCategoriesByName(array $categories, string $name): array
	{
		if (empty($name))
		{
			return $categories;
		}

		return array_filter(
			$categories,
			static fn(Category $category) => mb_stripos($category->getName(), $name) !== false
		);
	}

	public function getAmount(
		int $entityTypeId,
		int $userId,
		string $stageId,
		array $filter,
		?int $categoryId = null
	): ?float
	{
		$permissions = Container::getInstance()->getUserPermissions($userId);
		$isAllowed = $permissions
			->kanban()
			->canReadKanbanSumInStage($entityTypeId, $categoryId, $stageId)
		;

		if ($isAllowed)
		{
			$factory = Container::getInstance()->getFactory($entityTypeId);
			if ($factory === null)
			{
				return null;
			}

			return (float)$factory->getItemsOpportunityAccountAmount($filter, self::DEFAULT_CACHE_TTL);
		}

		return null;
	}
}
