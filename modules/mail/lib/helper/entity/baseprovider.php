<?php

namespace Bitrix\Mail\Helper\Entity;

abstract class BaseProvider
{
	private static array $entitiesCached = [];

	public function getEntityInfo(string $entityId): ?Entity
	{
		$entityInstance = $this->getFromCache($entityId);
		if ($entityInstance)
		{
			return $entityInstance;
		}

		$fetchedEntities = $this->fetchEntities([$entityId]);

		return reset($fetchedEntities) ?: null;
	}

	/**
	 * @return Entity[]
	 */
	public function getEntitiesInfo(array $entityIds): array
	{
		$entityObjects = [];
		$idsToFetch = [];

		foreach (array_unique($entityIds) as $entityId)
		{
			$entityInstance = $this->getFromCache($entityId);
			if ($entityInstance)
			{
				$entityObjects[$entityId] = $entityInstance;
			}
			else
			{
				$idsToFetch[] = $entityId;
			}
		}

		$fetchedEntities = $this->fetchEntities($idsToFetch);

		return $entityObjects + $fetchedEntities;
	}

	private function fetchEntities(array $entityIds): array
	{
		if (empty($entityIds))
		{
			return [];
		}

		$entitiesData = $this->getEntities($entityIds);
		$entityObjects = [];

		foreach ($entitiesData as $entityData)
		{
			$entity = $this->createEntityInstance($entityData);
			$key = $entity->getUniqueKeyValue();
			$entityObjects[$key] = $entity;
			self::$entitiesCached[$key] = $entity;
		}

		return $entityObjects;
	}

	private function getFromCache(string $entityId): ?Entity
	{
		return self::$entitiesCached[$entityId] ?? null;
	}

	abstract protected function createEntityInstance(array $entityData): Entity;

	abstract protected function getEntities(array $entityIds): array;
}
