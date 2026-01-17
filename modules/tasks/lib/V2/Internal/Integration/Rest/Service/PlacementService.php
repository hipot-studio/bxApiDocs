<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Rest\Service;

use Bitrix\Tasks\V2\Internal\Integration\Rest\Entity\Placement;
use Bitrix\Tasks\V2\Internal\Integration\Rest\Entity\PlacementCollection;
use Bitrix\Tasks\V2\Internal\Integration\Rest\Filter\PlacementFilterCollection;
use Bitrix\Tasks\V2\Internal\Integration\Rest\PlacementType;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Rest\Repository\PlacementRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Integration\Rest\Filter\PlacementFilterFactory;

class PlacementService
{
	private const TASK_CARD_PLACEMENT_TYPES = [
		PlacementType::TaskViewTopPanel,
		PlacementType::TaskViewSidebar,
		PlacementType::TaskViewTab,
	];

	private static ?PlacementFilterCollection $filterCollection = null;

	public function __construct(
		private readonly PlacementRepositoryInterface $placementRepository,
	)
	{

	}

	public function existsTaskCardPlacement(): bool
	{
		return $this->placementRepository->existsPlacementByTypes(...self::TASK_CARD_PLACEMENT_TYPES);
	}

	public function getTaskCardPlacements(int $taskId): PlacementCollection
	{
		$result = new PlacementCollection();

		foreach (self::TASK_CARD_PLACEMENT_TYPES as $type)
		{
			$placements = $this->placementRepository->getPlacementsByType($type);

			if (!$placements->isEmpty())
			{
				$filtered = $this->filterPlacements($placements, $taskId);

				$result = $result->merge($filtered);
			}
		}

		return $result;
	}

	private function filterPlacements(PlacementCollection $placementCollection, int $taskId): PlacementCollection
	{
		$filters = $this->getFilterCollection();

		return $placementCollection->filter(
			static fn (Placement $placement): bool => $filters->canApply($placement, $taskId)
		);
	}

	private function getFilterCollection(): PlacementFilterCollection
	{
		if (null === self::$filterCollection)
		{
			self::$filterCollection = PlacementFilterFactory::createDefaultFilterCollection();
		}

		return self::$filterCollection;
	}
}
