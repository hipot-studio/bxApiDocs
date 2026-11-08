<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\EventHandler\OnAfterSendMessageEvent;

use Bitrix\Im\V2\Chat\ExternalChat;
use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterSendMessageEvent;
use Bitrix\Tasks\Internals\UserOption\Option;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskUserOptionRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Role;
use Bitrix\Tasks\V2\Internal\Service\Task\UserOptionService;

class AutoUnmuteAuditor
{
	public function __construct(
		private readonly TaskRepositoryInterface $tasksRepository,
		private readonly TaskUserOptionRepositoryInterface $taskUserOptionRepository,
		private readonly UserOptionService $userOptionService,
		private readonly Logger $logger,
	)
	{
	}

	public function __invoke(AfterSendMessageEvent $event): void
	{
		$message = $event->getMessage();

		if ($message->isSystem())
		{
			return;
		}

		$authorId = $message->getAuthorId();
		if ($authorId === 0)
		{
			return;
		}

		if ($message->getAuthor()?->isBot())
		{
			return;
		}

		if (!method_exists($message, 'isSentFromUi') || !$message->isSentFromUi())
		{
			return;
		}

		try
		{
			$chat = $event->getChat();
			if ($chat->getId() === null)
			{
				return;
			}

			$taskId = (int)$chat->getEntityId();

			// No extra load: other AfterSendMessageEvent handlers read this task through the same request cache.
			$task = $this->tasksRepository->getById($taskId);
			if ($task === null)
			{
				return;
			}

			$auditorIds = $task->getMembers([Role::Auditor])->getIdList();
			if (!in_array($authorId, $auditorIds, true))
			{
				return;
			}

			$isMuted = $this->isChatMutedForUser($chat, $authorId)
				|| $this->isTaskMutedForUser($taskId, $authorId)
			;

			if (!$isMuted)
			{
				return;
			}

			$this->userOptionService->unmute($task->getId(), $authorId);
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
		}
	}

	protected function isChatMutedForUser(ExternalChat $chat, int $userId): bool
	{
		return $chat->getRelationByUserId($userId)?->getNotifyBlock() === true;
	}

	private function isTaskMutedForUser(int $taskId, int $userId): bool
	{
		return $this->taskUserOptionRepository->isSet(Option::MUTED, $taskId, $userId);
	}
}
