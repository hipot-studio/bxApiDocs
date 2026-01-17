<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Rest\Filter;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Rest\Entity\Placement;

class PlacementFilterCollection implements PlacementFilterInterface
{
	/** @var PlacementFilterInterface[] */
	private array $filters = [];

	public function addFilter(PlacementFilterInterface $filter): self
	{
		$this->filters[] = $filter;

		return $this;
	}

	public function canApply(Placement $placement, int $taskId): bool
	{
		foreach ($this->filters as $filter)
		{
			if (!$filter->canApply($placement, $taskId))
			{
				return false;
			}
		}

		return true;
	}
}

