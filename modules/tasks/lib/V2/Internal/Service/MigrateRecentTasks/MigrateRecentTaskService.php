<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\MigrateRecentTasks;

use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Esg\EgressInterface;

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

		if (!empty($task->chat))
		{
			return;
		}

		try
		{
			$this->egressService->createChatForExistingTask($task);
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
			return;
		}
	}
}
