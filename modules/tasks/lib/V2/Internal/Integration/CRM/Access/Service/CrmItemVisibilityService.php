<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\CRM\Access\Service;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\CrmItemReference;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\CrmItemVisibility;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Repository\CrmItemDataReader;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Repository\Mapper\CrmIdMapper;

final class CrmItemVisibilityService
{
	public function __construct(
		private readonly CrmIdMapper $crmIdMapper,
		private readonly CrmItemDataReader $crmItemDataReader,
	)
	{
	}

	/**
	 * @param list<string> $ids
	 * @return list<string>
	 */
	public function filterReadable(array $ids, int $userId): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		$idsByType = $this->groupByType($ids);

		return $this->getReadableIds($idsByType, $userId);
	}

	/**
	 * Classifies every reference for $userId as readable, hidden or deleted. Existence is read first and
	 * without a single protected field, so a name is never touched to learn whether an element is there;
	 * the read right is then asked once per unique element, after one attribute preload per entity type.
	 *
	 * @param list<CrmItemReference> $references
	 * @return array<string, CrmItemVisibility> reference key => visibility
	 */
	public function buildVisibilityMap(array $references, int $userId): array
	{
		if (empty($references) || !Loader::includeModule('crm'))
		{
			return [];
		}

		$entityIdsByType = [];
		foreach ($references as $reference)
		{
			$entityIdsByType[$reference->typeId][$reference->entityId] = $reference->entityId;
		}
		$entityIdsByType = array_map('array_values', $entityIdsByType);

		$existing = $this->crmItemDataReader->readExistence($entityIdsByType);

		$userPermissions = Container::getInstance()->getUserPermissions($userId);
		$itemPermissions = $userPermissions->item();
		$canReadMyCompany = null;

		$visibility = [];
		foreach ($entityIdsByType as $typeId => $entityIds)
		{
			$existingEntityIds = array_values(array_filter(
				array: $entityIds,
				callback: static fn (int $entityId): bool => array_key_exists(
					key: CrmItemReference::makeKey($typeId, $entityId),
					array: $existing,
				),
			));
			if (!empty($existingEntityIds))
			{
				$itemPermissions->preloadPermissionAttributes($typeId, $existingEntityIds);
			}

			foreach ($entityIds as $entityId)
			{
				$key = CrmItemReference::makeKey($typeId, $entityId);
				if (!array_key_exists($key, $existing))
				{
					$visibility[$key] = CrmItemVisibility::NotFound;

					continue;
				}

				if ($existing[$key])
				{
					// "My company" is readable through the company-of-mine rule, not through item rights.
					$canReadMyCompany ??= $userPermissions->myCompany()->canReadBaseFields();
					$isReadable = $canReadMyCompany;
				}
				else
				{
					$isReadable = $itemPermissions->canRead($typeId, $entityId);
				}

				$visibility[$key] = $isReadable ? CrmItemVisibility::Readable : CrmItemVisibility::Hidden;
			}
		}

		return $visibility;
	}

	private function groupByType(array $ids): array
	{
		$items = [];
		foreach ($ids as $id)
		{
			if (!is_string($id))
			{
				continue;
			}

			$parsedId = $this->crmIdMapper->mapFromId($id);
			if ($parsedId === null)
			{
				continue;
			}

			[$typeId, $entityId] = $parsedId;
			$items[$typeId][] = $entityId;
		}

		return $items;
	}

	private function getReadableIds(array $idsByType, int $userId): array
	{
		$userPermissions = Container::getInstance()->getUserPermissions($userId)->item();

		$readableIds = [];
		foreach ($idsByType as $typeId => $itemIds)
		{
			// This is the same bulk ACL flow used by tasks.v2.Task.CRM.Item.list.
			$userPermissions->preloadPermissionAttributes($typeId, $itemIds);

			foreach ($itemIds as $itemId)
			{
				if ($userPermissions->canRead($typeId, $itemId))
				{
					$id = $this->crmIdMapper->mapToId($typeId, $itemId);
					if (is_string($id))
					{
						$readableIds[] = $id;
					}
				}
			}
		}

		return $readableIds;
	}
}
