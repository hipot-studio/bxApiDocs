<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Analytics;

use Bitrix\Im\V2\Analytics\Event\ChatEvent;
use Bitrix\Im\V2\Analytics\Event\FileMessageEvent;
use Bitrix\Im\V2\Analytics\Event\MediaMessageEvent;
use Bitrix\Im\V2\Entity\File\Param\ParamName;
use Bitrix\Im\V2\Entity\File\ParamCollection;
use Bitrix\Im\V2\Integration\AI\Transcription\LaunchType;
use Bitrix\Im\V2\Integration\AI\Transcription\Result\TranscribeResult;
use Bitrix\Im\V2\Message;
use Bitrix\Main\Loader;

class FileAnalytics extends AbstractAnalytics
{
	protected const ATTACH_FILE = 'attach_file';
	protected const START_TRANSCRIPT = 'start_transcript';
	protected const FINISH_TRANSCRIPT = 'finish_transcript';

	public function addStartTranscript(TranscribeResult $result, LaunchType $launchType): void
	{
		$statusCode = $this->getTranscriptStatusCode($result);
		$fileParams = ParamCollection::getInstance($result->getFileItem()->diskFileId);

		$this->async(function () use ($statusCode, $fileParams, $launchType) {
			$this->sendTranscriptEvent(self::START_TRANSCRIPT, $statusCode, $fileParams, $launchType);
		});
	}

	public function addFinishTranscript(TranscribeResult $result, LaunchType $launchType): void
	{
		$statusCode = $this->getTranscriptStatusCode($result);
		$fileParams = ParamCollection::getInstance($result->getFileItem()->diskFileId);

		$this->async(function () use ($statusCode, $fileParams, $launchType) {
			$this->sendTranscriptEvent(self::FINISH_TRANSCRIPT, $statusCode, $fileParams, $launchType);
		});
	}

	public function addAttachFilesEvent(Message $message, ?string $fileType = null): void
	{
		$files = $message->getFiles();
		$fileCount = $files->count();
		if ($fileCount < 1)
		{
			return;
		}

		// Legacy contract: without the disk module the im-label goes out with no type at all.
		$this
			->createFileMessageEvent(self::ATTACH_FILE)
			?->setType(
				Loader::includeModule('disk')
					? ($fileType ?? FileMessageEvent::resolveFileType($files))
					: null
			)
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

	protected function sendTranscriptEvent(
		string $eventName,
		string $statusCode,
		ParamCollection $fileParams,
		LaunchType $launchType
	): void
	{
		$category = match (true)
		{
			$fileParams->getParam(ParamName::IsVoiceNote)?->getValue() => 'audiomessage',
			$fileParams->getParam(ParamName::IsVideoNote)?->getValue() => 'videomessage',
			default => null,
		};

		if ($category !== null)
		{
			$event = $this->createMediaMessageEvent($eventName, $category)
				?->setStatus($statusCode)
				?->setP2($this->formatLaunchType($launchType))
			;

			// transcripts do not report the project; p4 values of other chats stay untouched.
			// The resolved value is read instead of the rules behind it: a mirror of the branches of
			// ChatEvent::setChatP4() drifts apart from them in silence.
			if ($event !== null && str_starts_with((string)$event->getP4(), ChatEvent::PROJECT_P4_PREFIX))
			{
				$event->setP4(null);
			}

			$event?->send();
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

	protected function formatLaunchType(LaunchType $launchType): string
	{
		return 'launchType_' . $launchType->value;
	}
}
