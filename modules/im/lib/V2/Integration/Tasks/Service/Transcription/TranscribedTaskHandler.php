<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Service\Transcription;

use Bitrix\Im\V2\Integration\Tasks\Service\ChatTaskLinkService;
use Bitrix\Im\V2\Integration\Tasks\Service\CheckListService;
use Bitrix\Im\V2\Integration\Tasks\Service\ForwardService;
use Bitrix\Im\V2\Integration\Tasks\Service\TaskService;
use Bitrix\Im\V2\Integration\Tasks\Service\Transcription\Mapper\CheckListMapper;
use Bitrix\Im\V2\Integration\Tasks\Service\Transcription\Mapper\TaskMapper;
use Bitrix\Im\V2\Link\Task\TaskType;
use Bitrix\Im\V2\Message;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\V2\FormV2Feature;
use Bitrix\Tasks\V2\Internal\Entity\Task;

class TranscribedTaskHandler
{
	public function __construct(
		private readonly TaskMapper $taskMapper,
		private readonly CheckListMapper $checkListMapper,
		private readonly TaskService $taskService,
		private readonly CheckListService $checkListService,
		private readonly ChatTaskLinkService $chatTaskLinkService,
		private readonly UnresolvedFieldsNotifier $unresolvedFieldsNotifier,
		private readonly ForwardService $forwardService,
	)
	{
	}

	public function handle(array $taskData, Message $message, string $transcribedText): bool
	{
		if (empty($taskData) || !Loader::includeModule('tasks'))
		{
			return false;
		}

		$authorId = $message->getAuthorId();
		if ($authorId <= 0)
		{
			return false;
		}

		$taskToAdd = $this->taskMapper->convertFromTranscribedMessage($taskData, $message, $transcribedText);
		if ($taskToAdd === null)
		{
			return false;
		}

		try
		{
			$addedTask = $this->taskService->add($taskToAdd, $authorId);

			$checkListsToAdd = $this->checkListMapper->convertFromTranscribedMessage($taskData['checklists'] ?? []);

			if (!empty($checkListsToAdd))
			{
				$this->checkListService->add($addedTask, $checkListsToAdd, $authorId);
			}
		}
		catch (SystemException)
		{
			return false;
		}

		$this->chatTaskLinkService->linkFromMessage(
			taskId: $addedTask->getId(),
			message: $message,
			userId: $authorId,
			taskType: $this->getTaskType($addedTask),
		);

		$this->unresolvedFieldsNotifier->notify($addedTask, $taskData, $authorId);

		if (FormV2Feature::isOn())
		{
			$this->forwardService->forwardMessageToTask($message, $addedTask->getId(), $authorId);
		}

		return true;
	}

	private function getTaskType(Task $task): TaskType
	{
		if ($task->scenarios?->contains(Task\Scenario::Voice))
		{
			return TaskType::VoiceNoteAutoTask;
		}

		if ($task->scenarios?->contains(Task\Scenario::Video))
		{
			return TaskType::VideoNoteAutoTask;
		}

		return TaskType::Task;
	}
}
