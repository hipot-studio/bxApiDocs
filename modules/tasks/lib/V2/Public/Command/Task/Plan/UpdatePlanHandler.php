<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Plan;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Task\PlanService;

class UpdatePlanHandler
{
	public function __construct(
		private readonly PlanService $planService,
	)
	{

	}

	public function __invoke(UpdatePlanCommand $command): Entity\Task
	{
		$entity = new Entity\Task(
			id: $command->taskId,
			startPlanTs: $command->startPlanTs,
			endPlanTs: $command->endPlanTs,
			plannedDuration: $command->duration,
			matchesWorkTime: $command->matchesWorkTime,
			matchesSubTasksTime: $command->matchesSubTasksTime,
		);

		return $this->planService->update($entity, $command->config);
	}
}
