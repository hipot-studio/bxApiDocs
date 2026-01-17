<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Tasks\V2\Internal\Access\Task;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Rest\Service\PlacementService;

class Placement extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Task.Placement.list
	 */
	#[CloseSession]
	public function listAction(
		#[Task\Permission\Read]
		Entity\Task $task,
		PlacementService $placementService,
	): array
	{
		$taskId = (int)$task->getId();

		return [
			'placements' => $placementService->getTaskCardPlacements($taskId),
		];
	}
}
