<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Rest\Repository;

use Bitrix\Tasks\V2\Internal\Integration\Rest\Entity\PlacementCollection;
use Bitrix\Tasks\V2\Internal\Integration\Rest\PlacementType;

class InMemoryPlacementRepository implements PlacementRepositoryInterface
{
	private PlacementRepositoryInterface $placementRepository;

	private array $cache = [];

	public function __construct(PlacementRepository $placementRepository)
	{
		$this->placementRepository = $placementRepository;
	}

	public function existsPlacementByTypes(PlacementType ...$types): bool
	{
		foreach ($types as $type)
		{
			$cacheKey = $this->getCacheKey($type);

			if (isset($this->cache[$cacheKey]))
			{
				return true;
			}
		}

		return $this->placementRepository->existsPlacementByTypes(...$types);
	}

	public function getPlacementsByType(PlacementType $type): PlacementCollection
	{
		$cacheKey = $this->getCacheKey($type);

		if (isset($this->cache[$cacheKey]))
		{
			return $this->cache[$cacheKey];
		}

		$placements = $this->placementRepository->getPlacementsByType($type);

		$this->cache[$cacheKey] = $placements;

		return $placements;
	}

	private function getCacheKey(PlacementType $type): string
	{
		return $type->value;
	}
}
