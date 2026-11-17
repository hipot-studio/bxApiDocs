<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Disk\Service\Task;

use Bitrix\Disk\Uf\FileUserType;
use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Service\Interaction\InteractionOrigin;
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

	public function add(
		int $taskId,
		int $userId,
		array $fileIds,
		bool $useConsistency = false,
		InteractionOrigin $origin = InteractionOrigin::System,
	): ?Task
	{
		if (empty($fileIds))
		{
			return null;
		}

		$ufManager = $this->getUfManager();

		$currentAttachmentIds = $ufManager->GetUserFields(UserField::TASK, $taskId)[UserField::TASK_ATTACHMENTS]['VALUE'] ?? [];
		$currentAttachmentIds = is_array($currentAttachmentIds) ? $currentAttachmentIds : [];

		$currentAttachmentIdsMap = array_flip(
			Container::getInstance()->getDiskFileRepository()->getObjectIdsByAttachmentIds($currentAttachmentIds)
		);

		$currentAttachmentIds = array_flip($currentAttachmentIds);

		foreach ($currentAttachmentIds as $key => $value)
		{
			if (!isset($currentAttachmentIdsMap[$key]))
			{
				unset($currentAttachmentIds[$key]);
			}
		}

		$currentAttachmentIds = array_flip($currentAttachmentIds);

		$new = array_values(array_unique(array_merge($currentAttachmentIds, $fileIds)));

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
			origin: $origin,
		);

		// update changelog
		return $this->updateService->update(
			task: $task,
			config: $config,
		);
	}

	public function delete(
		int $taskId,
		int $userId,
		array $fileIds,
		bool $useConsistency = false,
		InteractionOrigin $origin = InteractionOrigin::System,
	): void
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

		$currentAttachmentIds = $ufManager->GetUserFields(UserField::TASK, $taskId)[UserField::TASK_ATTACHMENTS]['VALUE'] ?? [];
		$currentAttachmentIds = is_array($currentAttachmentIds) ? $currentAttachmentIds : [];

		if (empty($currentAttachmentIds))
		{
			throw new \RuntimeException('Task attachment state has changed.');
		}

		$currentAttachmentIdsMap = array_fill_keys(array_map('intval', $currentAttachmentIds), true);
		$fileIdsToRemove = [];
		$attachmentIdsByObjectId = null;

		foreach ($fileIds as $fileId)
		{
			[$type, $realValue] = FileUserType::detectType($fileId);
			if ($type === FileUserType::TYPE_NEW_OBJECT)
			{
				$attachmentIdsByObjectId ??= Container::getInstance()
					->getDiskFileRepository()
					->getUniqueAttachmentIdsByObjectIds($currentAttachmentIds)
				;

				$attachmentId = $attachmentIdsByObjectId[(int)$realValue] ?? null;
				if ($attachmentId === null)
				{
					throw new \RuntimeException('Task attachment state has changed.');
				}

				$fileIdsToRemove[] = $attachmentId;
			}
			elseif (isset($currentAttachmentIdsMap[(int)$realValue]))
			{
				$fileIdsToRemove[] = (int)$realValue;
			}
			else
			{
				throw new \RuntimeException('Task attachment state has changed.');
			}
		}

		if (count(array_unique($fileIdsToRemove)) !== count($fileIds))
		{
			throw new \RuntimeException('Task attachment state has changed.');
		}

		$new = array_diff($currentAttachmentIds, $fileIdsToRemove);
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
			origin: $origin,
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
