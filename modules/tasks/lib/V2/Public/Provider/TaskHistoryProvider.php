<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider;

use Bitrix\Tasks\V2\Internal\Access\Service\TaskAccessService;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskRightService;
use Bitrix\Tasks\V2\Internal\Entity\HistoryLogCollection;
use Bitrix\Tasks\V2\Internal\Repository\TaskHistoryRepositoryInterface;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskHistoryParams;

class TaskHistoryProvider
{
	public function __construct(
		private readonly TaskRightService $taskRightService,
		private readonly TaskHistoryRepositoryInterface $taskHistoryRepository,
	)
	{

	}

	public function tail(TaskHistoryParams $taskHistoryParams): HistoryLogCollection
	{
		if (
			$taskHistoryParams->checkAccess
			&& !$this->taskRightService->canView($taskHistoryParams->userId, $taskHistoryParams->taskId)
		)
		{
			return new HistoryLogCollection();
		}

		return $this->taskHistoryRepository->tail(
			taskId: $taskHistoryParams->taskId,
			offset: $taskHistoryParams->getOffset(),
			limit: $taskHistoryParams->getLimit(),
		);
	}
}
