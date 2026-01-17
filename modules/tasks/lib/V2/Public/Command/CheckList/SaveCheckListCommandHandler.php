<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\CheckList;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\CheckList\CheckListService;
use Bitrix\Tasks\V2\Internal\Service\Consistency\ConsistencyResolverInterface;

class SaveCheckListCommandHandler
{
	public function __construct(
		private readonly CheckListService $checkListService,
		private readonly ConsistencyResolverInterface $consistencyResolver,
	)
	{
	}

	public function __invoke(SaveCheckListCommand $command): Entity\Task
	{
		if ($command->useConsistency)
		{
			return $this->consistencyResolver->resolve('task.checklist.save')->wrap(
				fn (): Entity\Task => $this->checkListService->save(
					checklists: (array)$command->task->checklist,
					taskId: (int)$command->task->getId(),
					userId: $command->updatedBy,
					skipNotification: $command->skipNotification,
				)
			);
		}
		return $this->checkListService->save(
			checklists: (array)$command->task->checklist,
			taskId: (int)$command->task->getId(),
			userId: $command->updatedBy,
			skipNotification: $command->skipNotification,
		);
	}
}
