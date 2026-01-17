<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Rest\Filter;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Rest\Entity\Placement;
use Bitrix\Tasks\V2\Internal\Repository\Task\Select;

class GroupIdPlacementFilter implements PlacementFilterInterface
{
	public function canApply(Placement $placement, int $taskId): bool
	{
		$placementGroupOption = (string)($placement->options['groupId'] ?? '');

		if ($placementGroupOption === '')
		{
			return true;
		}

		$task = $this->getTaskById($taskId);
		if (!$task)
		{
			return false;
		}

		$groupId = (int)$task->group?->getId();
		if ($groupId <= 0)
		{
			return false;
		}

		$allowedGroupIds = explode(',', $placementGroupOption);
		$allowedGroupIds = array_map('intval', $allowedGroupIds);

		return in_array($groupId, $allowedGroupIds, true);
	}

	private function getTaskById(int $taskId): ?Entity\Task
	{
		$taskRepository = Container::getInstance()->getTaskReadRepository();

		return $taskRepository->getById(
			id: $taskId,
			select: new Select(
				group: true,
			),
		);
	}
}
