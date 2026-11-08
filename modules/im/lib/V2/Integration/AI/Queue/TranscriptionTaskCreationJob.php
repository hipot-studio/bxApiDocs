<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\Queue;

use Bitrix\AI\Engine\IEngine;
use Bitrix\AI\Result;
use Bitrix\Im\V2\Integration\AI\TaskCreation\AutoTaskQueueManager;
use Bitrix\Im\V2\Integration\AI\TaskCreation\Status;
use Bitrix\Im\V2\Integration\AI\TaskCreation\TranscriptionCreator;
use Bitrix\Im\V2\Integration\AI\Transcription\LaunchType;
use Bitrix\Im\V2\Integration\AI\Transcription\TranscribeManager;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Pull\Event\AutoTaskStatus;

class TranscriptionTaskCreationJob extends QueueJob
{
	public function processQueueJob(): void
	{
		/** @var IEngine $engine */
		$engine = $this->event->getParameter('engine');
		$context = $engine->getContext();
		$parameters = $context->getParameters();

		$fileId = (int)$parameters['fileId'];
		$diskFileId = (int)$parameters['diskFileId'];
		$messageId = (int)$parameters['messageId'];
		$userId = (int)$parameters['userId'];
		$launchType = $this->extractLaunchType($parameters);

		try
		{
			$result = $this->event->getParameter('result');
			if (!($result instanceof Result))
			{
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
			if ($message->getMessageId() === null || $message->getChatId() === null)
			{
				// the source message was deleted before the AI response returned: error, no task
				if ($userId > 0)
				{
					(new AutoTaskStatus((new Message())->setMessageId($messageId), $userId, Status::NotFound))->send();
				}

				return;
			}

			$transcribeManager = new TranscribeManager($fileId, $diskFileId, (int)$message->getChatId(), $messageId);
			$transcriptText = $transcribeManager->getFileTranscription()?->getPlainText();
			$data = $result->getJsonData() ?? [];

			if (empty($transcriptText))
			{
				// transcription yielded no text: error, no task (override ERR-013)
				if ($userId > 0)
				{
					(new AutoTaskStatus($message, $userId, Status::NotFound))->send();
				}

				return;
			}

			$creator = new TranscriptionCreator($message, $fileId, $diskFileId);
			$creator->createTask($data, $transcriptText, $userId, $launchType);
		}
		finally
		{
			$this->releaseDedupSlot($messageId, $launchType);
		}
	}

	public function processFailedJob(): void
	{
		/** @var IEngine $engine */
		$engine = $this->event->getParameter('engine');
		$parameters = $engine->getContext()->getParameters();

		$messageId = (int)$parameters['messageId'];
		$userId = (int)$parameters['userId'];
		$message = new Message($messageId);

		$this->releaseDedupSlot($messageId, $this->extractLaunchType($parameters));

		(new AutoTaskStatus($message, $userId, Status::NotFound))->send();
	}

	private function extractLaunchType(array $parameters): LaunchType
	{
		return LaunchType::fromString(
			isset($parameters['launchType']) && is_string($parameters['launchType'])
				? $parameters['launchType']
				: null
		);
	}

	/**
	 * Only the manual ready-transcription branch takes the dedup slot
	 * (@see TranscriptionCreator::sendAiQuery), and this job is where its attempt ends.
	 */
	private function releaseDedupSlot(int $messageId, LaunchType $launchType): void
	{
		if ($launchType !== LaunchType::Manual)
		{
			return;
		}

		AutoTaskQueueManager::getInstance()->delete([$messageId]);
	}
}
