<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Category;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions\Configuration;
use Bitrix\Crm\Service\UserPermissions\ConfigurationCallSite;
use Bitrix\Crm\Service\UserPermissions\ConfigurationScope;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\EntityTypeSettings;

/**
 * The permission model of the category domain, whole and in one place.
 *
 * Reading a category - and the stages of that category - follows the right to read items in it,
 * keeping the parity legacy `crm.dealcategory.list` / `crm.status.list` established. Writing follows
 * the configuration capability of the contour the entity type belongs to ({@see Configuration}), and
 * funnels and stages follow one operation of the contour each -
 * {@see Configuration::canManageFunnels()} and {@see Configuration::canManageStages()}. The entity
 * administrator check this domain used to ask, `isAdminForEntity()`, is left to an excluded contour
 * and to a rollback of the registration.
 *
 * Every `UserPermissions` call of the domain belongs here and nowhere else: legacy spread its own
 * checks over `crm.dealcategory.*` and `crm.status.*` and the two drifted apart to the point where
 * some methods check nothing at all.
 *
 * @internal
 */
class CategoryAccess
{
	/**
	 * Whether $userId may read the category $categoryId, and with it the stages of that category.
	 *
	 * Says nothing about the category existing: an id nobody granted permissions for is simply not
	 * readable. Existence is answered by
	 * {@see \Bitrix\Crm\V2\Internal\Repository\Category\CategoryRepositoryInterface::getById()}.
	 */
	public function canRead(int $entityTypeId, int $userId, int $categoryId): bool
	{
		if ($userId <= 0 || !$this->settingsOf($entityTypeId)?->isCategoriesSupported())
		{
			return false;
		}

		// What EntityPermissions\Category::canReadItems() computes, minus the detour through a legacy
		// category entity: the check itself needs no more than the two identifiers.
		return Container::getInstance()
			->getUserPermissions($userId)
			->entityType()
			->canReadItemsInCategory($entityTypeId, $categoryId)
		;
	}

	/**
	 * The categories of $entityTypeId that $userId may read, as ids. Empty for an entity type without
	 * categories and for an anonymous user.
	 *
	 * @return int[]
	 */
	public function getReadableCategoryIds(int $entityTypeId, int $userId): array
	{
		if ($userId <= 0 || !$this->settingsOf($entityTypeId)?->isCategoriesSupported())
		{
			return [];
		}

		$ids = Container::getInstance()
			->getUserPermissions($userId)
			->category()
			->getAvailableForReadingCategoriesIds($entityTypeId)
		;

		return array_map('intval', $ids);
	}

	/**
	 * Whether $userId may add, rename or delete a category of $entityTypeId. A `null` $categoryId is
	 * the category that does not exist yet - the one being added.
	 */
	public function canWriteCategory(int $entityTypeId, int $userId, ?int $categoryId): bool
	{
		if ($userId <= 0 || !$this->settingsOf($entityTypeId)?->isCategoriesSupported())
		{
			return false;
		}

		return $this->configurationOf($userId)->canManageFunnels(
			ConfigurationCallSite::CategoryDomainService,
			ConfigurationScope::ofEntityType($entityTypeId, $categoryId),
		);
	}

	/**
	 * Whether $userId may change the stages of the category $categoryId. Guarded by stage support
	 * alone: Lead and Quote have stages without having categories.
	 *
	 * The category is part of the check, as the category write right has it. For a contractor
	 * category that hands the decision to the warehouse configuration right instead of CRM
	 * administrator - the contour of such a category is excluded from the configuration model and
	 * keeps the former check ({@see ConfigurationScope}).
	 */
	public function canWriteStages(int $entityTypeId, int $userId, int $categoryId): bool
	{
		if ($userId <= 0 || !$this->settingsOf($entityTypeId)?->isStagesSupported())
		{
			return false;
		}

		return $this->configurationOf($userId)->canManageStages(
			ConfigurationCallSite::CategoryDomainService,
			ConfigurationScope::ofEntityType($entityTypeId, $categoryId),
		);
	}

	private function configurationOf(int $userId): Configuration
	{
		return Container::getInstance()->getUserPermissions($userId)->configuration();
	}

	/**
	 * `null` for an id that is not a CRM Item entity type at all - such a type supports nothing, so
	 * every operation of this domain is refused for it.
	 */
	private function settingsOf(int $entityTypeId): ?EntityTypeSettings
	{
		if (!EntityType::isValid($entityTypeId))
		{
			return null;
		}

		return EntityTypeSettings::of(EntityType::fromId($entityTypeId));
	}
}
