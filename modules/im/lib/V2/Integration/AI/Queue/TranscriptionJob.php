<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\Queue;

use Bitrix\AI\Result;
use Bitrix\Im\V2\Integration\AI\CopilotError;
use Bitrix\Im\V2\Integration\AI\Restriction;
use Bitrix\Im\V2\Integration\AI\TaskCreationManager;
use Bitrix\Im\V2\Integration\AI\Transcription\Item\Status;
use Bitrix\Im\V2\Integration\AI\Transcription\LaunchType;
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
		$launchType = LaunchType::fromString(
			isset($parameters['launchType']) && is_string($parameters['launchType'])
				? $parameters['launchType']
				: null
		);

		$transcribeManager = new TranscribeManager($fileId, $diskFileId, $chatId, $messageId);
		$transcribeResult = $this->getTranscribeResult($text ?? '', $transcribeManager);
		$transcribeManager->handleTranscriptionResponse($transcribeResult, $launchType);

		if (
			$transcribeResult->isSuccess()
			&& (new Restriction())->isAutoTaskActive()
		)
		{
			$message = new Message($messageId);
			(new TaskCreationManager($message, $transcribeResult->getFileItem()->getPlainText(), $fileId, $diskFileId))
				->sendAiQuery()
			;
		}
	}

	protected function getTranscribeResult(string $text, TranscribeManager $transcribeManager): TranscribeResult
	{
		$text = trim($text);

		if (empty($text))
		{
			$transcribeFileItem = $transcribeManager->createErrorFileItem();
			$error = new Error('', CopilotError::TRANSCRIPTION_TEXT_ERROR);

			return (new TranscribeResult($transcribeFileItem))->addError($error);
		}

		if (mb_strlen($text) > TranscribeManager::MAX_TRANSCRIPTION_CHARS)
		{
			$transcribeFileItem = $transcribeManager->createErrorFileItem();
			$error = new Error('', CopilotError::MAX_TRANSCRIPTION_CHARS);

			return (new TranscribeResult($transcribeFileItem))->addError($error);
		}

		$transcribeFileItem = $transcribeManager->createFileItem(Status::Success, $text);

		return new TranscribeResult($transcribeFileItem);
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
		$launchType = LaunchType::fromString(
			isset($parameters['launchType']) && is_string($parameters['launchType'])
				? $parameters['launchType']
				: null
		);

		$transcribeManager = new TranscribeManager($fileId, $diskFileId, $chatId, $messageId);
		$transcribeFileItem = $transcribeManager->createErrorFileItem();

		$transcribeResult = (new TranscribeResult($transcribeFileItem))->addError($error);
		$transcribeManager->handleTranscriptionResponse($transcribeResult, $launchType);
	}
}
