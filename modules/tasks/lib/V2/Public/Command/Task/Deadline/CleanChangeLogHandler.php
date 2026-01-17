<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Deadline;

use Bitrix\Tasks\V2\Internal\Repository\DeadlineChangeLogRepositoryInterface;

class CleanChangeLogHandler
{
	public function __construct(
		private readonly DeadlineChangeLogRepositoryInterface $deadlineChangeLogRepository,
	)
	{

	}

	public function __invoke(CleanChangeLogCommand $updateDeadlineCommand): bool
	{
		return $this->deadlineChangeLogRepository->clean(taskId: $updateDeadlineCommand->taskId);
	}
}
