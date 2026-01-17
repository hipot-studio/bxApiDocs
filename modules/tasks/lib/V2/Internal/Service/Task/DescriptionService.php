<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Main\Error;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Repository\TaskLogRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskReadRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Service\UpdateTaskService;

class DescriptionService
{
	public function __construct(
		private readonly ChecksumService $checksumService,
		private readonly UpdateTaskService $updateTaskService,
		private readonly UserRepositoryInterface $userRepository,
		private readonly TaskReadRepositoryInterface $taskReadRepository,
		private readonly TaskLogRepositoryInterface $taskLogRepository,
	)
	{

	}

	public function update(Task $task, int $userId, bool $forceUpdate = false, bool $useConsistency = false): Result
	{
		$result = new Result();

		$currentTask = $this->taskReadRepository->getById((int)$task->getId());
		if ($currentTask === null)
		{
			return $result->addError(new Error('Task not found'));
		}

		$lastChange = $this->taskLogRepository->getLastByField($currentTask->getId(), 'DESCRIPTION');

		if (
			$forceUpdate
			|| $lastChange?->userId === $userId
			|| $this->checksumService->calculateChecksum((string)$currentTask->description) === $task->descriptionChecksum
		)
		{
			$config = new UpdateConfig(
				userId: $userId,
				useConsistency: $useConsistency,
			);

			$task = $this->updateTaskService->update($task, $config);

			return $result->setObject($task);
		}

		$data = ['changed' => true];
		if ($lastChange === null)
		{
			return $result->setData($data);
		}

		if ($lastChange->userId > 0)
		{
			$data['changedBy'] = $this->userRepository->getByIds([$lastChange->userId])->findOneById($lastChange->userId);
		}

		if ($lastChange->createdDateTs > 0)
		{
			$data['changedTs'] = $lastChange->createdDateTs;
		}

		return $result->addError(new Error('Checksum mismatch', 'CHECKSUM_MISMATCH', $data));
	}
}
