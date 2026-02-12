<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Helper;

use Bitrix\Crm\Category\Entity\Category;
use Bitrix\Crm\EO_Status;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;

final class PermissionService
{
	public function getUserPermissions(int $userId): UserPermissions
	{
		return Container::getInstance()->getUserPermissions($userId);
	}

	public function canReadItems(int $userId, int $entityTypeId): bool
	{
		return $this
			->getUserPermissions($userId)
			->entityType()
			->canReadItems($entityTypeId)
		;
	}

	public function canReadAllItemsOfType(int $userId, int $entityTypeId, ?int $categoryId = null): bool
	{
		return $this
			->getUserPermissions($userId)
			->entityType()
			->canReadAllItemsOfType($entityTypeId, $categoryId)
		;
	}

	/**
	 * @param int $userId
	 * @param int $entityTypeId
	 * @param int|null $categoryId
	 *
	 * @return EO_Status[]
	 */
	public function getAvailableStages(int $userId, int $entityTypeId, ?int $categoryId = null): array
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if (!$factory || !$factory->isStagesSupported())
		{
			return [];
		}

		return $this
			->getUserPermissions($userId)
			->stage()
			->filterAvailableForReadingStages(
				$entityTypeId,
				$factory->getStages($categoryId)->getAll(),
				$categoryId
			)
		;
	}

	public function isStageAvailable(string $stageId, int $userId, int $entityTypeId, ?int $categoryId = null): bool
	{
		if (empty($stageId))
		{
			return false;
		}

		$stages = $this->getAvailableStages($userId, $entityTypeId, $categoryId);
		if (empty($stages))
		{
			return false;
		}

		$map = array_map(static fn(EO_Status $stage) => $stage->getStatusId(), $stages);

		return in_array($stageId, $map, true);
	}

	/**
	 * @param int $userId
	 * @param int $entityTypeId
	 *
	 * @return Category[]
	 */
	public function getAvailableCategories(int $userId, int $entityTypeId): array
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if (!$factory)
		{
			return [];
		}

		return $this
			->getUserPermissions($userId)
			->category()
			->filterAvailableForReadingCategories($factory->getCategories())
		;
	}
}
