<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\Queue;

use Bitrix\AI\Engine\IEngine;
use Bitrix\AI\Result;
use Bitrix\Im\V2\Integration\AI\TaskCreation\AutoTaskQueueManager;
use Bitrix\Im\V2\Integration\AI\TaskCreation\SingleMessageCreator;
use Bitrix\Im\V2\Integration\AI\TaskCreation\Status;
use Bitrix\Im\V2\Integration\AI\Transcription\LaunchType;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Pull\Event\AutoTaskStatus;

class SingleMessageTaskCreationJob extends QueueJob
{
	public function processQueueJob(): void
	{
		/** @var IEngine $engine */
		$engine = $this->event->getParameter('engine');
		$parameters = $engine->getContext()->getParameters();

		$messageId = (int)($parameters['messageId'] ?? 0);
		$userId = (int)($parameters['userId'] ?? 0);

		$queueManager = AutoTaskQueueManager::getInstance();

		$result = $this->event->getParameter('result');
		if (!($result instanceof Result))
		{
			$queueManager->delete([$messageId]);

			// no usable answer came back: the launch still owes the initiator a terminal verdict.
			// Loading the anchor is best effort - a hard-deleted row leaves the event without a chat,
			// but its id is what addresses every event of the operation
			if ($userId > 0)
			{
				$anchor = (new Message($messageId))->setMessageId($messageId);
				(new AutoTaskStatus($anchor, $userId, Status::NotFound))->send();
			}

			return;
		}

		$message = new Message($messageId);
		try
		{
			$creator = new SingleMessageCreator($message);

			// a hard-deleted source row drops the message/chat id; a soft-deleted message keeps the
			// row but replaces its text with the "message deleted" placeholder (IS_DELETED=Y). Both
			// are terminal: no task must be created from a missing or placeholder message.
			if ($message->getMessageId() === null || $message->getChatId() === null || $creator->isMessageDeleted())
			{
				if ($userId > 0)
				{
					(new AutoTaskStatus((new Message())->setMessageId($messageId), $userId, Status::NotFound))->send();
				}

				return;
			}

			$sourceText = $creator->extractSourceText();
			$data = $result->getJsonData() ?? [];

			$creator->createTask($data, $sourceText, $userId, LaunchType::Manual);
		}
		finally
		{
			$queueManager->delete([$messageId]);
		}
	}

	public function processFailedJob(): void
	{
		/** @var IEngine $engine */
		$engine = $this->event->getParameter('engine');
		$parameters = $engine->getContext()->getParameters();

		$messageId = (int)($parameters['messageId'] ?? 0);
		$userId = (int)($parameters['userId'] ?? 0);

		AutoTaskQueueManager::getInstance()->delete([$messageId]);

		if ($messageId <= 0 || $userId <= 0)
		{
			return;
		}

		$message = new Message($messageId);

		(new AutoTaskStatus($message, $userId, Status::NotFound))->send();
	}
}
