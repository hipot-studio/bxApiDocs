<?php

declare(strict_types=1);

namespace Bitrix\Booking\Entity\Resource;

use Bitrix\Booking\Entity\BaseEntityCollection;
use Bitrix\Booking\Entity\Slot\RangeCollection;
use Bitrix\Booking\Internals\Exception\Exception;
use DateTimeImmutable;

/**
 * @method Resource|null getFirstCollectionItem()
 * @method Resource[] getIterator()
 */
class ResourceCollection extends BaseEntityCollection
{
	private Resource|null $primary = null;

	public function __construct(Resource ...$resources)
	{
		foreach ($resources as $resource)
		{
			$this->collectionItems[] = $resource;
		}
	}

	/**
	 * First check virtual is_primary property of resource properties.
	 * Overwise explicitly set primary resource by rule "first resource is primary one"
	 */
	public static function mapFromArray(array $props): self
	{
		$resources = [];
		$primaryResource = null;
		foreach ($props as $resourceProps)
		{
			$resource = Resource::mapFromArray($resourceProps);
			if ($resourceProps['isPrimary'] ?? null)
			{
				$primaryResource = $resource;
			}
			$resources[] = $resource;
		}

		$resourceCollection = new ResourceCollection(...$resources);
		if (!$resourceCollection->isEmpty())
		{
			$resourceCollection->setPrimary($primaryResource ?? $resourceCollection->getFirstCollectionItem());
		}

		return $resourceCollection;
	}

	public function toArray(): array
	{
		return array_map(function (Resource $resource): array {
			$result = $resource->toArray();

			$result['isPrimary'] = $resource === $this->primary;

			return $result;
		}, $this->collectionItems);
	}

	/**
	 * @param ResourceCollection $collectionToCompare
	 */
	public function isEqual(BaseEntityCollection $collectionToCompare): bool
	{
		if (!parent::isEqual($collectionToCompare))
		{
			return false;
		}

		return $this->primary?->getId() === $collectionToCompare->getPrimary()?->getId();
	}

	public function diff(ResourceCollection $collectionToCompare): ResourceCollection
	{
		return new ResourceCollection(...$this->baseDiff($collectionToCompare));
	}

	public function mergeSlotRanges(DateTimeImmutable $date): RangeCollection
	{
		$result = null;

		/** @var Resource $resource */
		foreach ($this->collectionItems as $resource)
		{
			/** @var RangeCollection $slotRanges */
			$slotRanges = $resource->getSlotRanges();
			if ($slotRanges->isEmpty())
			{
				continue;
			}

			if ($result === null)
			{
				$result = $slotRanges;
			}
			else
			{
				$result = $slotRanges->merge($result, $date);
			}
		}

		return $result ?: new RangeCollection();
	}

	public function setPrimary(Resource $resource): ResourceCollection
	{
		$hasResourceInCollection = false;
		foreach ($this->collectionItems as $resourceItem)
		{
			if ($resource !== $resourceItem)
			{
				continue;
			}
			$hasResourceInCollection = true;
		}
		if (!$hasResourceInCollection)
		{
			throw new Exception('Resource not exists in collection');
		}
		$this->primary = $resource;

		return $this;
	}

	public function getPrimary(): Resource|null
	{
		return $this->primary;
	}
}
