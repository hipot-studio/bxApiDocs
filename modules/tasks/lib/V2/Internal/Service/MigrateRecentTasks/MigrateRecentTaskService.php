<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\MigrateRecentTasks;

use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Esg\EgressInterface;
use Throwable;

class MigrateRecentTaskService
{
	public function __construct(
		private readonly TaskRepositoryInterface $taskRepository,
		private readonly EgressInterface $egressService,
		private readonly Logger $logger,
	)
	{
	}

	public function execute(int $taskId): void
	{
		$task = $this->taskRepository->getById($taskId);

		if ($task === null)
		{
			return;
		}

		if (!empty($task->chat))
		{
			return;
		}

		try
		{
			$this->egressService->createChatForExistingTask($task);
		}
		catch (Throwable $e)
		{
			$this->logger->logWarning(
				$e->getMessage() . ' Trace: ' . $e->getTraceAsString(),
				'TASKS_MIGRATE_DEBUG'
			);
		}
	}
}
