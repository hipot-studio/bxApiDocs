<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\Task\RestoreTaskArchiveCommand;

use Bitrix\Bizproc\Internal\Repository\TaskArchiveRepository\RestoreTaskArchiveRepository;
use Bitrix\Bizproc\Public\Service\Task\RestoreTaskArchiveService;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;

class RestoreTaskArchiveCommandHandler
{
	private const ARCHIVE_LIMIT = 3;

	private RestoreTaskArchiveRepository $repository;
	private RestoreTaskArchiveService $restoreService;

	public function __construct()
	{
		$this->repository = new RestoreTaskArchiveRepository();
		$this->restoreService = new RestoreTaskArchiveService();
	}

	public function __invoke(RestoreTaskArchiveCommand $command): bool
	{
		$workflowIds = $this->repository->getWorkflowIdsWithRecentTasks($command->limit);
		if (empty($workflowIds))
		{
			return false;
		}

		$remainingBudget = $command->chunkSize;
		$connection = Application::getConnection();

		foreach ($workflowIds as $workflowId)
		{
			$sentinelId = $this->repository->getLastRecentArchiveId($workflowId);
			$archiveIds = $this->repository->getArchiveIdsByWorkflowId($workflowId, self::ARCHIVE_LIMIT);

			if ($sentinelId !== null)
			{
				$archiveIds = array_diff($archiveIds, [$sentinelId]);
			}

			foreach ($archiveIds as $archiveId)
			{
				$restored = $this->restoreArchiveChunk($connection, $archiveId, $remainingBudget);
				$remainingBudget -= $restored;

				if ($remainingBudget <= 0)
				{
					return true;
				}
			}

			if ($sentinelId !== null && $remainingBudget > 0)
			{
				$restored = $this->restoreArchiveChunk($connection, $sentinelId, $remainingBudget);
				$remainingBudget -= $restored;

				if ($remainingBudget <= 0)
				{
					return true;
				}
			}
		}

		return $remainingBudget < $command->chunkSize;
	}

	private function restoreArchiveChunk(Connection $connection, int $archiveId, int $chunkSize): int
	{
		$archive = $this->repository->getArchiveData($archiveId);
		if ($archive === null)
		{
			return 0;
		}

		$connection->startTransaction();
		try
		{
			$restoredCount = $this->restoreService->restoreChunk(
				(int)$archive['ID'],
				(string)$archive['WORKFLOW_ID'],
				(string)$archive['TASKS_DATA'],
				$chunkSize,
			);
			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();
			throw $exception;
		}

		return $restoredCount;
	}
}
