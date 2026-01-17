<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Rest\Repository;

use Bitrix\Main\Loader;
use Bitrix\Rest\PlacementTable;
use Bitrix\Tasks\V2\Internal\Integration\Rest\Entity\PlacementCollection;
use Bitrix\Tasks\V2\Internal\Integration\Rest\PlacementType;
use Bitrix\Tasks\V2\Internal\Integration\Rest\Repository\Mapper\PlacementMapper;

class PlacementRepository implements PlacementRepositoryInterface
{
	public function __construct(
		private readonly PlacementMapper $placementMapper,
	)
	{

	}

	public function existsPlacementByTypes(PlacementType ...$types): bool
	{
		if (empty($types))
		{
			return false;
		}

		if (!Loader::includeModule('rest'))
		{
			return false;
		}

		$placement = PlacementTable::query()
			->setSelect(['ID'])
			->whereIn('PLACEMENT', array_map(static fn(PlacementType $type) => $type->value, $types))
			->setLimit(1)
			->exec()
			->fetch();

		return (bool)$placement;
	}

	public function getPlacementsByType(PlacementType $type): PlacementCollection
	{
		if (!Loader::includeModule('rest'))
		{
			return new PlacementCollection();
		}

		$placementList = PlacementTable::getHandlersList($type->value);

		return $this->placementMapper->mapToCollection($placementList, $type);
	}
}
