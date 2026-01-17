<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Service\Transcription;

use Bitrix\Im\V2\Message;

class TranscriptionHandler
{
	private const TYPE_FIELD = 'type';
	private const TASK_DATA_FIELD = 'task_data';
	private const RESULT_DATA_FIELD = 'result_data';

	public function __construct(
		private readonly TranscribedTaskHandler $taskHandler,
		private readonly TranscribedResultHandler $resultHandler,
	)
	{
	}

	public function handle(array $data, Message $message, string $transcribedText): bool
	{
		$type = EntityType::tryFrom($data[self::TYPE_FIELD] ?? '');

		return match ($type)
		{
			EntityType::Task => $this->processTask($data, $message, $transcribedText),
			EntityType::Result => $this->processResult($data, $message),
			EntityType::Unknown => true,
			default => false,
		};
	}

	private function processTask(array $data, Message $message, string $transcribedText): bool
	{
		$taskData = $data[self::TASK_DATA_FIELD] ?? [];
		if (!is_array($taskData))
		{
			return false;
		}

		return $this->taskHandler->handle($taskData, $message, $transcribedText);
	}

	private function processResult(array $data, Message $message): bool
	{
		$resultData = $data[self::RESULT_DATA_FIELD] ?? '';
		if (!is_string($resultData))
		{
			return false;
		}

		return $this->resultHandler->handle($resultData, $message);
	}
}
