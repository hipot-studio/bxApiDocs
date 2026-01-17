<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Rest\Repository;

use Bitrix\Tasks\V2\Internal\Integration\Rest\Entity\PlacementCollection;
use Bitrix\Tasks\V2\Internal\Integration\Rest\PlacementType;

interface PlacementRepositoryInterface
{
	public function existsPlacementByTypes(PlacementType ...$types);
	public function getPlacementsByType(PlacementType $type): PlacementCollection;
}
