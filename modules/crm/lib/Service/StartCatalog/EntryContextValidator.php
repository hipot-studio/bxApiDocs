<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\StartCatalog;

use Bitrix\Crm\RelationIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Service\UserPermissions;

/**
 * The place a form was opened from, checked before it is signed into a ticket: a readable parent the
 * smart process can have, a funnel of this smart process and a stage of that funnel.
 */
class EntryContextValidator
{
	public function sanitize(int $entityTypeId, array $context, UserPermissions $permissions): array
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return [];
		}

		$sanitized = [];

		$parentTypeId = (int)($context['parentTypeId'] ?? 0);
		$parentId = (int)($context['parentId'] ?? 0);
		if ($this->isParentAllowed($entityTypeId, $parentTypeId, $parentId, $permissions))
		{
			$sanitized['parentTypeId'] = $parentTypeId;
			$sanitized['parentId'] = $parentId;
		}

		$categoryId = (int)($context['categoryId'] ?? 0);
		if ($categoryId > 0 && $factory->isCategoriesSupported() && $factory->getCategory($categoryId) !== null)
		{
			$sanitized['categoryId'] = $categoryId;
		}
		else
		{
			$categoryId = null;
		}

		$stageId = (string)($context['stageId'] ?? '');
		if ($stageId !== '' && $factory->isStagesEnabled() && $this->isStageOfCategory($factory, $categoryId, $stageId))
		{
			$sanitized['stageId'] = $stageId;
		}

		return $sanitized;
	}

	public function isParentAllowed(
		int $entityTypeId,
		int $parentTypeId,
		int $parentId,
		UserPermissions $permissions,
	): bool
	{
		if ($parentTypeId <= 0 || $parentId <= 0)
		{
			return false;
		}

		$isBound = Container::getInstance()->getRelationManager()->areTypesBound(
			new RelationIdentifier($parentTypeId, $entityTypeId),
		);

		return $isBound && $permissions->item()->canRead($parentTypeId, $parentId);
	}

	/**
	 * Without a funnel in the context the item lands in the funnel by default, so the stage is asked
	 * of that one.
	 */
	private function isStageOfCategory(Factory $factory, ?int $categoryId, string $stageId): bool
	{
		if ($categoryId === null && $factory->isCategoriesSupported())
		{
			$categoryId = $factory->getDefaultCategory()?->getId();
		}

		foreach ($factory->getStages($categoryId) as $stage)
		{
			if ((string)$stage->getStatusId() === $stageId)
			{
				return true;
			}
		}

		return false;
	}
}
