<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Esg\Handler;

use Bitrix\Tasks\V2\Internal\Integration\Im\ChatNotificationInterface;
use Bitrix\Tasks\V2\Internal\Integration\Im\NotificationType;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;
use Bitrix\Tasks\V2\Public\Command\Task\UpdateTaskCommand;

class TaskUpdateUserFieldsHandler
{
	public function __construct(
		private readonly UserRepositoryInterface $userRepository,
		private readonly ChatNotificationInterface $chatNotification
	)
	{
	}

	public function handle(UpdateTaskCommand $command): void
	{
		$taskBeforeUpdate = $command->taskBeforeUpdate;

		if ($taskBeforeUpdate === null)
		{
			return;
		}

		$changes = $command->task->diff($taskBeforeUpdate);
		$triggeredBy = $this->userRepository
			->getByIds([$command->config->getUserId()])
			->findOneById($command->config->getUserId())
		;

		foreach ($changes as $key => $change)
		{
			match ($key)
			{
				'crmItemIds' => $this->handleCrmChanges($command, $triggeredBy),
				'fileIds' => $this->handleFilesChanges($command, $triggeredBy),
				default => null,
			};
		}
	}

	public function handleCrmChanges(UpdateTaskCommand $command, $triggeredBy): void
	{
		$this->chatNotification->notify(
			type: NotificationType::TaskCrmItemsChanged,
			task: $command->task,
			args: ['triggeredBy' => $triggeredBy],
		);
	}

	public function handleFilesChanges(UpdateTaskCommand $command, $triggeredBy): void
	{
		$filesBeforeUpdate = is_array($command->taskBeforeUpdate->fileIds) && $command->taskBeforeUpdate->fileIds !== ['']
			? $command->taskBeforeUpdate->fileIds
			: []
		;
		$filesAfterUpdate = is_array($command->task->fileIds) && $command->task->fileIds !== ['']
			? $command->task->fileIds
			: []
		;

		$addedFiles = array_diff($filesAfterUpdate, $filesBeforeUpdate);
		$removedFiles = array_diff($filesBeforeUpdate, $filesAfterUpdate);

		if (!empty($addedFiles))
		{
			$this->chatNotification->notify(
				type: NotificationType::TaskAttachmentAdded,
				task: $command->task,
				args: [
					'triggeredBy' => $triggeredBy,
					'fileIds' => array_values($addedFiles),
				],
			);
		}

		if (!empty($removedFiles))
		{
			$this->chatNotification->notify(
				type: NotificationType::TaskAttachmentRemoved,
				task: $command->task,
				args: [
					'triggeredBy' => $triggeredBy,
					'fileIds' =>  array_values($removedFiles),
				],
			);
		}
	}
}
