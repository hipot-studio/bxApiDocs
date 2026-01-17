<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Service\Transcription;

use Bitrix\Im\V2\Integration\Tasks\Service\MessageSender;
use Bitrix\Tasks\V2\FormV2Feature;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\NotifyAiFailedToResolveFields;
use Bitrix\Tasks\V2\Internal\Service\Comment\AiFailedToResolveResponsibleComment;
use Bitrix\Tasks\V2\Public\Service\CommentService;

class UnresolvedFieldsNotifier
{
	public function __construct(
		private readonly MessageSender $messageSender,
		private readonly CommentService $commentService,
	)
	{
	}

	public function notify(Task $addedTask, array $sourceTaskData, int $authorId): void
	{
		$unresolvedFields = [];

		if ($this->isResponsibleMissing($sourceTaskData, $addedTask, $authorId))
		{
			$unresolvedFields[] = 'responsible';
		}

		if ($this->isDeadlineMissing($sourceTaskData, $addedTask))
		{
			$unresolvedFields[] = 'deadline';
		}

		if (empty($unresolvedFields))
		{
			return;
		}

		if (FormV2Feature::isOn())
		{
			$notification = new NotifyAiFailedToResolveFields($addedTask, $unresolvedFields);

			$this->messageSender->sendMessage($addedTask, $notification);
		}
		else
		{
			$this->sendComment($addedTask, $unresolvedFields, $authorId);
		}
	}

	private function sendComment(Task $addedTask, array $unresolvedFields, int $authorId): void
	{
		$hasResponsible = in_array('responsible', $unresolvedFields, true);

		if (!$hasResponsible)
		{
			return;
		}

		$comment = new AiFailedToResolveResponsibleComment($addedTask);

		$this->commentService->send($addedTask, $comment, $authorId);
	}

	private function isResponsibleMissing(array $taskData, Task $task, int $authorId): bool
	{
		return empty($taskData['responsible']) && $task->responsible?->id === $authorId;
	}

	private function isDeadlineMissing(array $taskData, Task $task): bool
	{
		return empty($taskData['deadline']) && (int)($task->deadlineTs ?? 0) === 0;
	}
}
