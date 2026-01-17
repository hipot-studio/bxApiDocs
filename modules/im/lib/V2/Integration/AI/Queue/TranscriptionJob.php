<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\Queue;

use Bitrix\AI\Result;
use Bitrix\Im\V2\Integration\AI\Restriction;
use Bitrix\Im\V2\Integration\AI\TaskCreationManager;
use Bitrix\Im\V2\Integration\AI\Transcription\Item\Status;
use Bitrix\Im\V2\Integration\AI\Transcription\Result\TranscribeResult;
use Bitrix\Im\V2\Integration\AI\Transcription\TranscribeManager;
use Bitrix\Im\V2\Message;
use Bitrix\Main\Error;

class TranscriptionJob extends QueueJob
{
	public function processQueueJob(): void
	{
		/** @var \Bitrix\AI\Engine\IEngine $engine */
		$engine = $this->event->getParameter('engine');
		$parameters = $engine->getContext()->getParameters();
		$result = $this->event->getParameter('result');

		if (!($result instanceof Result))
		{
			return;
		}

		$text = $result->getPrettifiedData();
		$fileId = (int)$parameters['fileId'];
		$diskFileId = (int)$parameters['diskFileId'];
		$chatId = (int)$parameters['chatId'];
		$messageId = (int)$parameters['messageId'];

		$transcribeManager = new TranscribeManager($fileId, $diskFileId, $chatId, $messageId);

		if (!empty($text) && mb_strlen($text) <= TranscribeManager::MAX_TRANSCRIPTION_CHARS)
		{
			$transcribeFileItem = $transcribeManager->createFileItem(Status::Success, trim($text));
			$result = (new TranscribeResult($transcribeFileItem));
		}
		else
		{
			$transcribeFileItem = $transcribeManager->createErrorFileItem();
			$error = new Error('', 'MAX_TRANSCRIPTION_CHARS');
			$result = (new TranscribeResult($transcribeFileItem))->addError($error);
		}

		$transcribeManager->handleTranscriptionResponse($result);

		if (
			$result->isSuccess()
			&& (new Restriction())->isAutoTaskActive()
		)
		{
			$message = new Message($messageId);
			(new TaskCreationManager($message, $transcribeFileItem->getPlainText(), $fileId, $diskFileId))
				->sendAiQuery();
		}
	}

	public function processFailedJob(): void
	{
		/** @var \Bitrix\AI\Engine\IEngine $engine */
		$engine = $this->event->getParameter('engine');
		$parameters = $engine->getContext()->getParameters();
		$error = $this->event->getParameter('error') ?? null;

		if (!($error instanceof Error))
		{
			return;
		}

		$fileId = (int)$parameters['fileId'];
		$diskFileId = (int)$parameters['diskFileId'];
		$chatId = (int)$parameters['chatId'];
		$messageId = (int)$parameters['messageId'];

		$transcribeManager = new TranscribeManager($fileId, $diskFileId, $chatId, $messageId);
		$transcribeFileItem = $transcribeManager->createErrorFileItem();

		$transcribeResult = (new TranscribeResult($transcribeFileItem))->addError($error);
		$transcribeManager->handleTranscriptionResponse($transcribeResult);
	}
}
