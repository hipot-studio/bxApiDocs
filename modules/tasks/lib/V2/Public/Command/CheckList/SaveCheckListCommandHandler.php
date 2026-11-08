<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\CheckList;

use Bitrix\Tasks\V2\Internal\Service\CheckList\CheckListService;
use Bitrix\Tasks\V2\Internal\Service\CheckList\SaveCheckListResult;
use Bitrix\Tasks\V2\Internal\Service\Consistency\ConsistencyResolverInterface;

class SaveCheckListCommandHandler
{
	public function __construct(
		private readonly CheckListService $checkListService,
		private readonly ConsistencyResolverInterface $consistencyResolver,
	)
	{
	}

	public function __invoke(SaveCheckListCommand $command): SaveCheckListResult
	{
		$taskId = (int)$command->task->getId();

		return $this->save($command, $taskId);
	}

	private function save(SaveCheckListCommand $command, int $taskId): SaveCheckListResult
	{
		if ($command->useConsistency)
		{
			return $this->consistencyResolver->resolve('task.checklist.save')->wrap(
				fn (): SaveCheckListResult => $this->checkListService->save(
					checklists: (array)$command->task->checklist,
					taskId: $taskId,
					userId: $command->updatedBy,
					skipNotification: $command->skipNotification,
					baseRevision: $command->baseRevision,
					fromAi: $command->fromAi,
				)
			);
		}

		return $this->checkListService->save(
			checklists: (array)$command->task->checklist,
			taskId: $taskId,
			userId: $command->updatedBy,
			skipNotification: $command->skipNotification,
			baseRevision: $command->baseRevision,
			fromAi: $command->fromAi,
		);
	}
}
