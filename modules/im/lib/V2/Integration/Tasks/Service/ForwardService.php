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
		$chat = $this->chatProvider->getChatByTaskId($taskId);

		if ($chat instanceof Chat\NullChat)
		{
			return (new Result())->addError(new ChatError(ChatError::NOT_FOUND));
		}

		$service = (new Message\Forward\ForwardService($chat))->setContextUser($userId);

		$messageCollection = (new MessageCollection())->add($message);

		return $service->createMessages($messageCollection);
	}
}
