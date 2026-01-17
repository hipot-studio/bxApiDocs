<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider;

use Bitrix\Tasks\V2\Internal\Access\Service\TaskRightService;
use Bitrix\Tasks\V2\Internal\Entity\HistoryGridLogCollection;
use Bitrix\Tasks\V2\Internal\Repository\TaskHistoryGridRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Grid\History\Service\TaskHistoryGridLogEnrichService;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskHistoryGridParams;

class TaskHistoryGridProvider
{
	public function __construct(
		private readonly TaskRightService $taskRightService,
		private readonly TaskHistoryGridRepositoryInterface $taskHistoryGridRepository,
		private readonly TaskHistoryGridLogEnrichService $taskHistoryGridEnrichService,
	)
	{

	}

	public function tail(TaskHistoryGridParams $taskHistoryGridParams): HistoryGridLogCollection
	{
		if (
			$taskHistoryGridParams->checkAccess
			&& !$this->taskRightService->canView($taskHistoryGridParams->userId, $taskHistoryGridParams->taskId)
		)
		{
			return new HistoryGridLogCollection();
		}

		$logCollection = $this->taskHistoryGridRepository->tail(
			taskId: $taskHistoryGridParams->taskId,
			offset: $taskHistoryGridParams->pager->getOffset(),
			limit: $taskHistoryGridParams->pager->getLimit(),
		);

		return $this->taskHistoryGridEnrichService->fill(
			historyGridLogCollection: $logCollection,
			userId: $taskHistoryGridParams->userId,
		);
	}
}
