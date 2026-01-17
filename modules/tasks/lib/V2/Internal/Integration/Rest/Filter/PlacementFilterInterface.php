<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Rest\Filter;

use Bitrix\Tasks\V2\Internal\Integration\Rest\Entity\Placement;

interface PlacementFilterInterface
{
	public function canApply(Placement $placement, int $taskId): bool;
}
