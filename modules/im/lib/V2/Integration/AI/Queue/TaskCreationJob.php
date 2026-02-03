<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\Queue;

use Bitrix\AI\Engine\IEngine;
use Bitrix\AI\Result;
use Bitrix\Im\V2\Integration\AI\TaskCreation\Status;
use Bitrix\Im\V2\Integration\AI\TaskCreationManager;
use Bitrix\Im\V2\Integration\AI\Transcription\TranscribeManager;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Pull\Event\AutoTaskStatus;

class TaskCreationJob extends QueueJob
{
	public function processQueueJob(): void
	{
		/** @var IEngine $engine */
		$engine = $this->event->getParameter('engine');
		$context = $engine->getContext();
		$parameters = $context->getParameters();

		$result = $this->event->getParameter('result');

		if (!($result instanceof Result))
		{
			return;
		}

		$fileId = (int)$parameters['fileId'];
		$diskFileId = (int)$parameters['diskFileId'];
		$messageId = (int)$parameters['messageId'];

		$message = new Message($messageId);
		$transcribeManager = new TranscribeManager($fileId, $diskFileId, (int)$message->getChatId(), $messageId);
		$transcriptText = $transcribeManager->getFileTranscription()?->getPlainText();
		$data = $result->getJsonData() ?? [];

		(new TaskCreationManager($message, $transcriptText, $fileId, $diskFileId))->createTask($data);
	}

	public function processFailedJob(): void
	{
		/** @var IEngine $engine */
		$engine = $this->event->getParameter('engine');
		$parameters = $engine->getContext()->getParameters();

		$messageId = (int)$parameters['messageId'];
		$message = new Message($messageId);

		(new AutoTaskStatus($message, Status::NotFound))->send();
	}
}
