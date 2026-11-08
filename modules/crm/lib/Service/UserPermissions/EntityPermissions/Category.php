<?php

namespace Bitrix\Crm\Service\UserPermissions\EntityPermissions;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions\Configuration;
use Bitrix\Crm\Service\UserPermissions\ConfigurationCallSite;
use Bitrix\Crm\Service\UserPermissions\ConfigurationScope;

/**
 * @internal
 * Do not use directly, only through \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions()->category()
 */

class Category
{
	public function __construct(
		private readonly Type $entityType,
		private readonly Configuration $configuration,
	)
	{
	}

	public function canAdd(\Bitrix\Crm\Category\Entity\Category $category): bool
	{
		return $this->canManageFunnels($category);
	}

	public function canUpdate(\Bitrix\Crm\Category\Entity\Category $category): bool
	{
		return $this->canManageFunnels($category);
	}

	public function canDelete(\Bitrix\Crm\Category\Entity\Category $category): bool
	{
		return $this->canManageFunnels($category);
	}

	/**
	 * Creating, renaming and deleting a funnel are one right of the contour the entity type belongs to
	 * ({@see Configuration::canManageFunnels()}), and the surfaces reaching this class inherit it: the
	 * category controller, the funnel functions of the AI assistant, the configuration import of the
	 * REST module and the mobile funnel controller. Until the operations are registered it stays the
	 * entity administrator check all of them used to ask.
	 */
	private function canManageFunnels(\Bitrix\Crm\Category\Entity\Category $category): bool
	{
		return $this->configuration->canManageFunnels(
			ConfigurationCallSite::CategoryPermissions,
			ConfigurationScope::ofEntityType($category->getEntityTypeId(), $category->getId()),
		);
	}

	public function canReadItems(\Bitrix\Crm\Category\Entity\Category $category): bool
	{
		return $this->entityType->canReadItemsInCategory($category->getEntityTypeId(), $category->getId());
	}

	public function canAddItems(\Bitrix\Crm\Category\Entity\Category $category): bool
	{
		return $this->entityType->canAddItemsInCategory($category->getEntityTypeId(), $category->getId());
	}

	public function canUpdateItems(\Bitrix\Crm\Category\Entity\Category $category): bool
	{
		return $this->entityType->canUpdateItemsInCategory($category->getEntityTypeId(), $category->getId());
	}

	/**
	 * @return int[]
	 */
	public function getAvailableForReadingCategoriesIds(int $entityTypeId): array
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if (!$factory || !$factory->isCategoriesSupported())
		{
			return [];
		}

		return array_map(fn($category) => $category->getId(), $this->filterAvailableForReadingCategories($factory->getCategories()));
	}

	/**
	 * @return int[]
	 */
	public function getAvailableForAddingCategoriesIds(int $entityTypeId): array
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if (!$factory || !$factory->isCategoriesSupported())
		{
			return [];
		}

		return array_map(fn($category) => $category->getId(), $this->filterAvailableForAddingCategories($factory->getCategories()));
	}

	/**
	 * @return int[]
	 */
	public function getAvailableForAddingCategoriesIdsWithCurrent(int $entityTypeId, int $currentCategoryId): array
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if (!$factory || !$factory->isCategoriesSupported())
		{
			return [];
		}

		return array_map(
			fn($category) => $category->getId(),
			$this->filterAvailableForAddingCategoriesWithCurrent($factory->getCategories(), $currentCategoryId),
		);
	}

	/**
	 * @return int[]
	 */
	public function getAvailableForUpdatingCategoriesIds(int $entityTypeId): array
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if (!$factory || !$factory->isCategoriesSupported())
		{
			return [];
		}

		return array_map(fn($category) => $category->getId(), $this->filterAvailableForUpdatingCategories($factory->getCategories()));
	}

	/**
	 * @return \Bitrix\Crm\Category\Entity\Category[]
	 */
	public function filterAvailableForAddingCategories(array $categories): array
	{
		return array_values(array_filter($categories, [$this, 'canAddItems']));
	}

	/**
	 * @param \Bitrix\Crm\Category\Entity\Category[] $categories
	 * @return \Bitrix\Crm\Category\Entity\Category[]
	 */
	public function filterAvailableForAddingCategoriesWithCurrent(array $categories, int $currentCategoryId): array
	{
		return array_values(array_filter(
			$categories,
			fn($category) => $category->getId() === $currentCategoryId || $this->canAddItems($category),
		));
	}

	/**
	 * @return \Bitrix\Crm\Category\Entity\Category[]
	 */
	public function filterAvailableForReadingCategories(array $categories): array
	{
		return array_values(array_filter($categories, [$this, 'canReadItems']));
	}

	/**
	 * @return \Bitrix\Crm\Category\Entity\Category[]
	 */
	public function filterAvailableForUpdatingCategories(array $categories): array
	{
		return array_values(array_filter($categories, [$this, 'canUpdateItems']));
	}
}
