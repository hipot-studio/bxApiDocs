<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Rest\Repository\Mapper;

use Bitrix\Tasks\V2\Internal\Integration\Rest\Entity\Placement;
use Bitrix\Tasks\V2\Internal\Integration\Rest\Entity\PlacementCollection;
use Bitrix\Tasks\V2\Internal\Integration\Rest\PlacementType;

class PlacementMapper
{
	public function mapToCollection(array $placements, PlacementType $type): PlacementCollection
	{
		$entities = [];

		foreach ($placements as $placement)
		{
			$entities[] = $this->mapToEntity($placement, $type);
		}

		return new PlacementCollection(...$entities);
	}

	public function mapToEntity(array $placement, ?PlacementType $type = null): Placement
	{
		return Placement::mapFromArray([
			'id' => $placement['ID'] ?? null,
			'appId' => $placement['APP_ID'] ?? null,
			'title' => $placement['TITLE'] ?? null,
			'description' => $placement['DESCRIPTION'] ?? null,
			'options' => $placement['OPTIONS'] ?? null,
			'type' => $type?->value,
		]);
	}
}
