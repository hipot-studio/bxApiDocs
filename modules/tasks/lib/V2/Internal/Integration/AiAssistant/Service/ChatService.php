<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service;

use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\AccessDeniedException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\InvalidIdentifierException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\NotFoundException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\SendChatMessageDto;
use Bitrix\Tasks\V2\Internal\Integration\Im\Access\ChatAccessService;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\NotifyCustomMessage;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;

class ChatService
{
	public function __construct(
		private readonly ChatAccessService $chatAccessService,
		private readonly TaskRepositoryInterface $taskRepository,
		private readonly MessageSenderInterface $messageSender,
	)
	{
	}

	/**
	 * @throws AccessDeniedException
	 * @throws InvalidIdentifierException
	 * @throws NotFoundException
	 */
	public function sendMessage(SendChatMessageDto $dto, int $userId): bool
	{
		if ($dto->taskId <= 0)
		{
			throw new InvalidIdentifierException();
		}

		$task = $this->taskRepository->getById($dto->taskId);
		if ($task === null || $task->chatId === null)
		{
			throw new NotFoundException();
		}

		if (!$this->chatAccessService->canSendMessage($task->chatId, $userId))
		{
			throw new AccessDeniedException();
		}

		$user = new User($userId);

		$message = new NotifyCustomMessage($user, $dto->text);

		$this->messageSender->sendMessage($task, $message);

		return true;
	}
}
