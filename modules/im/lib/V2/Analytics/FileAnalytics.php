<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Analytics;

use Bitrix\Im\V2\Analytics\Event\FileMessageEvent;
use Bitrix\Im\V2\Analytics\Event\MediaMessageEvent;
use Bitrix\Im\V2\Entity\File\Param\ParamName;
use Bitrix\Im\V2\Entity\File\ParamCollection;
use Bitrix\Im\V2\Integration\AI\Transcription\Result\TranscribeResult;
use Bitrix\Im\V2\Message;

class FileAnalytics extends AbstractAnalytics
{
	protected const ATTACH_FILE = 'attach_file';
	protected const START_TRANSCRIPT = 'start_transcript';
	protected const FINISH_TRANSCRIPT = 'finish_transcript';

	public function addStartTranscript(TranscribeResult $result): void
	{
		$statusCode = $this->getTranscriptStatusCode($result);
		$fileParams = ParamCollection::getInstance($result->getFileItem()->diskFileId);

		$this->async(function () use ($statusCode, $fileParams) {
			$this->sendTranscriptEvent(self::START_TRANSCRIPT, $statusCode, $fileParams);
		});
	}

	public function addFinishTranscript(TranscribeResult $result): void
	{
		$statusCode = $this->getTranscriptStatusCode($result);
		$fileParams = ParamCollection::getInstance($result->getFileItem()->diskFileId);

		$this->async(function () use ($statusCode, $fileParams) {
			$this->sendTranscriptEvent(self::FINISH_TRANSCRIPT, $statusCode, $fileParams);
		});
	}

	public function addAttachFilesEvent(Message $message): void
	{
		$files = $message->getFiles();
		$fileCount = $files->count();
		if ($fileCount < 1)
		{
			return;
		}

		$this
			->createFileMessageEvent(self::ATTACH_FILE)
			?->setFilesType($files)
			?->setFileP3($fileCount)
			?->send()
		;
	}

	protected function getTranscriptStatusCode(TranscribeResult $result): string
	{
		$statusCode = 'success';
		if (!$result->isSuccess())
		{
			$statusCode = 'error_' . mb_strtolower($result->getError()?->getCode());
		}

		return $statusCode;
	}

	protected function sendTranscriptEvent(string $eventName, string $statusCode, ParamCollection $fileParams): void
	{
		$category = match (true)
		{
			$fileParams->getParam(ParamName::IsVoiceNote)?->getValue() => 'audiomessage',
			$fileParams->getParam(ParamName::IsVideoNote)?->getValue() => 'videomessage',
			default => null,
		};

		if ($category !== null)
		{
			$this->createMediaMessageEvent($eventName, $category)
				?->setStatus($statusCode)
				?->send();
		}
	}

	protected function createMediaMessageEvent(string $eventName, string $category): ?MediaMessageEvent
	{
		if (!$this->isChatTypeAllowed($this->chat))
		{
			return null;
		}

		return (new MediaMessageEvent($eventName, $this->chat, $this->getContext()->getUserId(), $category));
	}

	protected function createFileMessageEvent(
		string $eventName,
	): ?FileMessageEvent
	{
		if (!$this->isChatTypeAllowed($this->chat))
		{
			return null;
		}

		return (new FileMessageEvent($eventName, $this->chat, $this->getContext()->getUserId()));
	}
}
