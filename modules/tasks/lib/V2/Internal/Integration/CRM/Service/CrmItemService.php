<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\CRM\Service;

use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Service\UpdateTaskService;
use CUserTypeManager;

class CrmItemService
{
	public function __construct(
		private readonly UpdateTaskService $updateTaskService,
	)
	{

	}

	public function set(int $taskId, int $userId, array $crmItemIds, bool $useConsistency = false): void
	{
		$changedBy = new User(id: $userId);

		$task = new Task(
			id: $taskId,
			changedTs: time(),
			changedBy: $changedBy,
			crmItemIds: $crmItemIds,
		);

		$config = new UpdateConfig(
			userId: $userId,
			useConsistency: $useConsistency,
		);

		// update changelog
		$this->updateTaskService->update(
			task: $task,
			config: $config,
		);
	}

	public function add(int $taskId, int $userId, array $crmItemIds, bool $useConsistency = false): void
	{
		if (empty($crmItemIds))
		{
			return;
		}

		$ufManager = $this->getUfManager();

		$current = $ufManager->GetUserFields(UserField::TASK, $taskId)[UserField::TASK_CRM]['VALUE'] ?? [];
		$current = is_array($current) ? $current : [];

		$new = array_unique(array_merge($current, $crmItemIds));
		$new = array_values($new);

		$changedBy = new User(id: $userId);
		$task = new Task(
			id: $taskId,
			changedTs: time(),
			changedBy: $changedBy,
			crmItemIds: $new,
		);

		$config = new UpdateConfig(
			userId: $userId,
			useConsistency: $useConsistency,
		);

		$this->updateTaskService->update(
			task: $task,
			config: $config,
		);
	}

	public function delete(int $taskId, int $userId, array $crmItemIds, bool $useConsistency = false): void
	{
		if (empty($crmItemIds))
		{
			return;
		}

		$ufManager = $this->getUfManager();

		$current = $ufManager->GetUserFields(UserField::TASK, $taskId)[UserField::TASK_CRM]['VALUE'] ?? [];
		$current = is_array($current) ? $current : [];

		$new = array_diff($current, $crmItemIds);
		$new = array_values($new);

		$changedBy = new User(id: $userId);
		$task = new Task(
			id: $taskId,
			changedTs: time(),
			changedBy: $changedBy,
			crmItemIds: $new,
		);

		$config = new UpdateConfig(
			userId: $userId,
			useConsistency: $useConsistency,
		);

		$this->updateTaskService->update(
			task: $task,
			config: $config,
		);
	}

	private function getUfManager(): CUserTypeManager
	{
		global $USER_FIELD_MANAGER;

		return $USER_FIELD_MANAGER;
	}
}
