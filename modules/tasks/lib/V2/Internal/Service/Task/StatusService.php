<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\Task\Status;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;

class StatusService
{
	public function __construct(
		private readonly UpdateService $updateService,
		private readonly StatusResolver $statusResolver,
	)
	{

	}

	public function start(int $taskId, UpdateConfig $config): array
	{
		return $this->updateTaskStatus($taskId, Status::InProgress, $config);
	}

	public function renew(int $taskId, UpdateConfig $config): array
	{
		return $this->updateTaskStatus($taskId, Status::Pending, $config);
	}

	public function complete(int $taskId, UpdateConfig $config): array
	{
		$status = $this->statusResolver->resolveForComplete($taskId, $config->getUserId());
		
		return $this->updateTaskStatus($taskId, $status, $config);
	}

	public function approve(int $taskId, UpdateConfig $config): array
	{
		return $this->updateTaskStatus($taskId, Status::Completed, $config);
	}

	public function pause(int $taskId, UpdateConfig $config): array
	{
		return $this->updateTaskStatus($taskId, Status::Pending, $config);
	}

	public function defer(int $taskId, UpdateConfig $config): array
	{
		return $this->updateTaskStatus($taskId, Status::Deferred, $config);
	}

	public function disapprove(int $taskId, UpdateConfig $config): array
	{
		return $this->updateTaskStatus($taskId, Status::Pending, $config);
	}

	private function updateTaskStatus(int $taskId, Status $status, UpdateConfig $config): array
	{
		$entity = new Task(
			id: $taskId,
			status: $status
		);

		return $this->updateService->update($entity, $config);
	}
}