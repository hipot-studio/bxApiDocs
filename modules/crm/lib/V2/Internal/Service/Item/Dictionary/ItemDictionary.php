<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Dictionary;

use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\EntityType;

/**
 * @internal
 */
class ItemDictionary
{
	/**
	 * @param array<int, int[]> $filterEntityIdsMapByType
	 * @return array<int, int[]>
	 */
	public function getAllIdMapByEntityType(int $userId, array $filterEntityIdsMapByType): array
	{
		$result = [];
		foreach ($filterEntityIdsMapByType as $entityTypeId => $filterEntityIds)
		{
			$entityType = EntityType::fromId($entityTypeId);
			$result[$entityType->getId()] = $this->getAllIdByEntityType($userId, $entityType, $filterEntityIds);
		}

		return $result;
	}

	/**
	 * @param int[] $filterEntityIds
	 * @return int[]
	 */
	public function getAllIdByEntityType(int $userId, EntityType $entityType, array $filterEntityIds): array
	{
		if ($userId <= 0 || empty($filterEntityIds))
		{
			return [];
		}

		$items = \Bitrix\Crm\V2\Public\Provider\Item\ItemProvider::forEntityType($entityType)
			->withAccessCheck($userId)
			->getByIds(
				array_values(array_unique($filterEntityIds)),
				$this->isCategoryRestricted($entityType) ? ['id', 'categoryId'] : ['id'],
			)
		;

		$result = [];
		foreach ($items as $item)
		{
			if ($this->isCategoryRestricted($entityType) && $item->getCategoryId() !== 0)
			{
				continue;
			}

			$result[] = $item->getId();
		}

		return $result;
	}

	private function isCategoryRestricted(EntityType $entityType): bool
	{
		return in_array(
			$entityType->getId(),
			[EntityType::company()->getId(), EntityType::contact()->getId()],
			true,
		);
	}
}
