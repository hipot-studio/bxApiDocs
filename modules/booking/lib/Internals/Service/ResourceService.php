<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service;

use Bitrix\Booking\Entity;
use Bitrix\Booking\Internals\Exception\Exception;
use Bitrix\Booking\Internals\Exception\Resource\ExternalResourceException;
use Bitrix\Booking\Internals\Repository\ORM\BookingResourceRepository;
use Bitrix\Booking\Internals\Repository\ORM\ResourceLinkedEntityRepository;
use Bitrix\Booking\Internals\Repository\ResourceRepositoryInterface;
use Bitrix\Booking\Internals\Repository\ResourceTypeRepositoryInterface;
use Bitrix\Booking\Internals\Service\DelayedTask\Data\ResourceLinkedEntitiesChangedData;
use Bitrix\Booking\Provider\Params\Resource\ResourceFilter;
use Bitrix\Booking\Provider\Params\Resource\ResourceSelect;

class ResourceService
{
	public function __construct(
		private readonly BookingResourceRepository $bookingResourceRepository,
		private readonly ResourceRepositoryInterface $resourceRepository,
		private readonly ResourceTypeRepositoryInterface $resourceTypeRepository,
		private readonly ResourceLinkedEntityRepository $resourceLinkedEntityRepository,
	)
	{
	}

	public function handleResourceRelations(
		Entity\Booking\Booking $booking,
		Entity\Resource\ResourceCollection $resourceCollection
	): void
	{
		$this->bookingResourceRepository->link($booking, $resourceCollection);
		$booking->setResourceCollection($resourceCollection);
	}

	public function handleResourceEntities(
		Entity\Resource\Resource $resource,
		Entity\Resource\ResourceLinkedEntityCollection $newEntities
	): ResourceLinkedEntitiesChangedData|null
	{
		$currentEntities = $resource->getEntityCollection();

		if ($newEntities->isEqual($currentEntities))
		{
			return null;
		}

		if ($newEntities->isEmpty() && !$currentEntities->isEmpty())
		{
			$this->resourceLinkedEntityRepository->unLink($resource, $currentEntities);

			return new ResourceLinkedEntitiesChangedData(
				resourceId: $resource->getId(),
				deleted: $currentEntities,
				added: null,
			);
		}

		$toDelete = $currentEntities->diff($newEntities);
		if (!$toDelete->isEmpty())
		{
			$this->resourceLinkedEntityRepository->unLink($resource, $toDelete);
		}

		$toAdd = $newEntities->diff($currentEntities);
		if (!$toAdd->isEmpty())
		{
			$this->resourceLinkedEntityRepository->link($resource, $toAdd);
		}

		if ($toDelete->isEmpty() && $toAdd->isEmpty())
		{
			return null;
		}

		return new ResourceLinkedEntitiesChangedData(
			resourceId: $resource->getId(),
			deleted: $toDelete->isEmpty() ? null : $toDelete,
			added: $toAdd->isEmpty() ? null : $toAdd,
		);
	}

	public function loadResourceCollection(
		Entity\Resource\ResourceCollection $resourceCollection,
	): Entity\Resource\ResourceCollection
	{
		$resourceIds = $this->getExternalResourceIds($resourceCollection) ?? [];
		/** @var Resource $resource */
		foreach ($resourceCollection as $resource)
		{
			$resourceIds[] = $resource->getId();
		}

		if (empty($resourceIds))
		{
			throw new Exception('Empty resource collection');
		}

		$primaryResourceId = $resourceCollection->getPrimary()?->getId();

		$resources = $this->resourceRepository->getList(
			filter: (new ResourceFilter([
				'ID' => $resourceIds,
				'INCLUDE_DELETED' => true,
			]))->prepareFilter(),
			select: new ResourceSelect(),
		);

		$primaryResource = null;
		foreach ($resources as $resource)
		{
			if ($primaryResourceId === $resource->getId())
			{
				$primaryResource = $resource;

				break;
			}
		}

		if ($primaryResourceId && $primaryResource)
		{
			$resources->setPrimary($primaryResource);
		}

		return $resources;
	}

	private function getExternalResourceIds(Entity\Resource\ResourceCollection $resourceCollection): array
	{
		if ($resourceCollection->isEmpty())
		{
			return [];
		}

		$externalResourceIds = [];
		$externalResourcesByType = [];
		$externalTypes = [];
		$externalTypesDbCache = [];

		/** @var Entity\Resource\Resource $resource */
		foreach ($resourceCollection as $resource)
		{
			if (!$resource->isExternal())
			{
				continue;
			}

			if (!$resource->getType()?->getModuleId())
			{
				throw new ExternalResourceException('ModuleId of resource type is not specified');
			}

			if (!$resource->getType()?->getCode())
			{
				throw new ExternalResourceException('Code of resource type is not specified');
			}

			$externalType = $this->getResourceExternalType(
				$externalTypesDbCache,
				$resource->getType(),
			);

			$externalResourcesByType[$externalType->getId()][$resource->getExternalId()] = $resource;
			$externalTypes[$externalType->getId()] = $externalType;
		}

		foreach ($externalResourcesByType as $externalTypeId => $typedExternalResources)
		{
			$externalResources = $this->resourceRepository->getList(
				filter: (new ResourceFilter([
					'TYPE_ID' => $externalTypeId,
					'EXTERNAL_ID' => array_keys($typedExternalResources),
				]))->prepareFilter(),
				select: new ResourceSelect(),
			);

			foreach ($typedExternalResources as $externalId => $externalResource)
			{
				$foundResource = null;
				foreach ($externalResources as $dbResource)
				{
					if ($dbResource->getExternalId() === $externalId)
					{
						$foundResource = $dbResource;
						break;
					}
				}

				if ($foundResource === null)
				{
					$externalResource->setType($externalTypes[$externalTypeId]);
					$savedResourceId = $this->resourceRepository->save($externalResource);
					$externalResourceIds[] = $savedResourceId;
				}
				else
				{
					$externalResourceIds[] = $foundResource->getId();
				}
			}
		}

		return $externalResourceIds;
	}

	private function getResourceExternalType(
		array &$externalTypesDbCache,
		Entity\ResourceType\ResourceType $resourceType,
	): Entity\ResourceType\ResourceType
	{
		$resourceTypeModuleId = $resourceType->getModuleId();
		$resourceTypeCode = $resourceType->getCode();
		$cacheKey = sprintf('%s|%s', $resourceTypeModuleId, $resourceTypeCode);

		$externalType = $externalTypesDbCache[$cacheKey] ?? null;
		if ($externalType)
		{
			return $externalType;
		}

		$externalType = $this->resourceTypeRepository->getByModuleIdAndCode(
			$resourceTypeModuleId,
			$resourceTypeCode
		);
		$externalTypesDbCache[$cacheKey] = $externalType;

		if ($externalType)
		{
			return $externalType;
		}

		$externalTypeId = $this->resourceTypeRepository->save($resourceType);
		$externalType = $this->resourceTypeRepository->getById($externalTypeId);
		$externalTypesDbCache[$cacheKey] = $externalType;

		return $externalType;
	}
}
