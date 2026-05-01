<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Rest\Repository;

use Bitrix\Tasks\V2\Internal\Integration\Rest\Entity\PlacementCollection;
use Bitrix\Tasks\V2\Internal\Integration\Rest\PlacementType;

class InMemoryPlacementRepository implements PlacementRepositoryInterface
{
	private PlacementRepositoryInterface $placementRepository;

	/** @var PlacementCollection[] */
	private array $placementCollectionCache = [];

	private array $existenceCache = [];

	public function __construct(PlacementRepository $placementRepository)
	{
		$this->placementRepository = $placementRepository;
	}

	public function existsPlacementByTypes(PlacementType ...$types): bool
	{
		$existenceCacheKey = $this->getExistenceCacheKey(...$types);

		if (isset($this->existenceCache[$existenceCacheKey]))
		{
			return $this->existenceCache[$existenceCacheKey];
		}

		foreach ($types as $type)
		{
			$cacheKey = $this->getCacheKey($type);

			if (isset($this->placementCollectionCache[$cacheKey]))
			{
				$actualExistence = !$this->placementCollectionCache[$cacheKey]->isEmpty();

				$this->existenceCache[$existenceCacheKey] = $actualExistence;

				return $actualExistence;
			}
		}

		$existenceCheckResult = $this->placementRepository->existsPlacementByTypes(...$types);

		$this->existenceCache[$existenceCacheKey] = $existenceCheckResult;

		return $existenceCheckResult;
	}

	public function getPlacementsByType(PlacementType $type): PlacementCollection
	{
		$cacheKey = $this->getCacheKey($type);

		if (isset($this->placementCollectionCache[$cacheKey]))
		{
			return $this->placementCollectionCache[$cacheKey];
		}

		$placements = $this->placementRepository->getPlacementsByType($type);

		$this->placementCollectionCache[$cacheKey] = $placements;

		return $placements;
	}

	private function getCacheKey(PlacementType $type): string
	{
		return $type->value;
	}

	private function getExistenceCacheKey(PlacementType ...$types): string
	{
		$values = array_map(fn (PlacementType $type) => $type->value, $types);
		sort($values);

		return implode('_', $values);
	}
}
