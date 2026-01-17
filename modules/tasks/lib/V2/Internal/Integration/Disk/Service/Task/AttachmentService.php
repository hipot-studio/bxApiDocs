<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Disk\Service\Task;

use Bitrix\Disk\Uf\FileUserType;
use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Entity\DiskFile;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Service\UpdateTaskService;
use CUserTypeManager;

class AttachmentService
{
	public function __construct(
		private readonly UpdateTaskService $updateService,
	)
	{

	}

	public function add(int $taskId, int $userId, array $fileIds, bool $useConsistency = false): ?Task
	{
		if (empty($fileIds))
		{
			return null;
		}

		$ufManager = $this->getUfManager();

		$current = $ufManager->GetUserFields(UserField::TASK, $taskId)[UserField::TASK_ATTACHMENTS]['VALUE'] ?? [];
		$current = is_array($current) ? $current : [];

		$new = array_unique(array_merge($current, $fileIds));
		$new = array_values($new);

		$changedBy = new User(id: $userId);

		$task = new Task(
			id: $taskId,
			fileIds: $new,
			changedTs: time(),
			changedBy: $changedBy,
		);

		$config = new UpdateConfig(
			userId: $userId,
			useConsistency: $useConsistency,
		);

		// update changelog
		return $this->updateService->update(
			task: $task,
			config: $config,
		);
	}

	public function delete(int $taskId, int $userId, array $fileIds, bool $useConsistency = false): void
	{
		if (!Loader::includeModule('disk'))
		{
			return;
		}

		if (empty($fileIds))
		{
			return;
		}

		$ufManager = $this->getUfManager();

		$current = $ufManager->GetUserFields(UserField::TASK, $taskId)[UserField::TASK_ATTACHMENTS]['VALUE'] ?? [];
		$current = is_array($current) ? $current : [];

		if (empty($current))
		{
			return;
		}

		$fileIdsToRemove = [];
		$currentAttachments = Container::getInstance()->getDiskFileRepository()->getByIds($current);

		foreach ($fileIds as $fileId)
		{
			[$type, $realValue] = FileUserType::detectType($fileId);
			if ($type === FileUserType::TYPE_NEW_OBJECT)
			{
				/** @var DiskFile $attachment */
				foreach ($currentAttachments as $attachment)
				{
					if ($attachment->customData['objectId'] === (int)$realValue)
					{
						$fileIdsToRemove[] = $attachment->id;

						break;
					}
				}
			}
			else
			{
				$fileIdsToRemove[] = $fileId;
			}
		}

		$new = array_diff($current, $fileIdsToRemove);
		$new = array_values($new);

		$changedBy = new User(id: $userId);

		// if no files left, we need to set empty value
		if (empty($new))
		{
			$new[] = '';
		}

		$task = new Task(
			id: $taskId,
			fileIds: $new,
			changedTs: time(),
			changedBy: $changedBy,
		);

		$config = new UpdateConfig(
			userId: $userId,
			useConsistency: $useConsistency,
		);

		// update changelog
		$this->updateService->update(
			task: $task,
			config: $config,
		);
	}

	protected function getUfManager(): CUserTypeManager
	{
		global $USER_FIELD_MANAGER;

		return $USER_FIELD_MANAGER;
	}
}
