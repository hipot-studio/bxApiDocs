<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Service;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Integration\Tasks\Provider\ChatProvider;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Result;

class ForwardService
{
	public function __construct(
		private readonly ChatProvider $chatProvider,
	)
	{
	}

	public function forwardMessageToTask(Message $message, int $taskId, int $userId): Result
	{
		return $this->forwardMessagesToTask((new MessageCollection())->add($message), $taskId, $userId);
	}

	public function forwardMessagesToTask(MessageCollection $messages, int $taskId, int $userId): Result
	{
		if ($messages->isEmpty())
		{
			return new Result();
		}

		$chat = $this->chatProvider->getChatByTaskId($taskId);

		if ($chat instanceof Chat\NullChat)
		{
			return (new Result())->addError(new ChatError(ChatError::NOT_FOUND));
		}

		// the forward is the only trace of the source the task gets, so it carries a message of a
		// discussion as well: the chat of the task is not a place a user forwards anything into
		$service = (new Message\Forward\ForwardService($chat))
			->markAsSystemForward()
			->setContextUser($userId)
		;

		return $service->createMessages($messages);
	}
}
