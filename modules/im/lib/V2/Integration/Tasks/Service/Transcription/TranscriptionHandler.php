<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Service\Transcription;

use Bitrix\Im\V2\Integration\AI\TaskCreation\Status;
use Bitrix\Im\V2\Integration\AI\Transcription\LaunchType;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Pull\Event\AutoTaskStatus;

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

	public function handle(
		array $data,
		Message $message,
		int $initiatorId,
		LaunchType $launchType = LaunchType::Auto
	): bool
	{
		$verdict = EntityType::tryFrom($data[self::TYPE_FIELD] ?? '');
		$taskData = is_array($data[self::TASK_DATA_FIELD] ?? null) ? $data[self::TASK_DATA_FIELD] : [];

		if ($verdict === EntityType::Task && !empty($taskData))
		{
			if ($launchType === LaunchType::Manual)
			{
				// a recognized assignment left without a usable title is still a task the initiator
				// asked for, so the title falls back to the default one instead of a refusal ([P1.T2])
				$taskData = TranscribedTaskHandler::withDefaultTitle($taskData);
				// the two-items rule belongs to the prompt of the manual launch. An automatic launch
				// runs on task_extraction_prompt, which we neither own nor can read, so its rule about
				// check lists is unknown to us and its outcomes must not be changed from here
				$taskData = TranscribedTaskHandler::withoutSingleItemCheckLists($taskData);
			}

			return $this->processTask($taskData, $message, $initiatorId, false);
		}

		// the "result" entity stays an outcome of the single-message flow in both launch modes
		if ($launchType === LaunchType::Auto || $verdict === EntityType::Result)
		{
			return $this->processWithoutTaskData($verdict, $data, $message, $initiatorId);
		}

		// an automatic launch returned above, so this is a manual one: the check list rule of its
		// prompt holds for a task built without a verdict just as it does for a recognized one
		$taskData = TranscribedTaskHandler::buildFallbackTaskData($taskData);
		$taskData = TranscribedTaskHandler::withoutSingleItemCheckLists($taskData);

		return $this->processTask($taskData, $message, $initiatorId, true);
	}

	private function processWithoutTaskData(
		?EntityType $verdict,
		array $data,
		Message $message,
		int $initiatorId
	): bool
	{
		if ($verdict === EntityType::Result)
		{
			return $this->processResult($data, $message, $initiatorId);
		}

		(new AutoTaskStatus($message, $initiatorId, Status::NotFound))->send();

		// an explicit "unknown" is a valid answer of the model, anything else means an unusable one
		return $verdict === EntityType::Unknown;
	}

	private function processTask(
		array $taskData,
		Message $message,
		int $initiatorId,
		bool $builtWithoutVerdict
	): bool
	{
		(new AutoTaskStatus($message, $initiatorId, Status::TaskCreationStarted, true))->send();

		$created = $this->taskHandler->handle(
			$taskData,
			$message,
			$initiatorId,
			builtWithoutVerdict: $builtWithoutVerdict,
		);
		if (!$created)
		{
			// the delegate can fail without a terminal status (tasks module off, mapper null or
			// a SystemException in add); without this the initiator panel hangs forever. On success
			// TaskCreationCompleted is sent from ChatTaskLinkService, so there is no double terminal
			(new AutoTaskStatus($message, $initiatorId, Status::NotFound))->send();
		}

		return $created;
	}

	private function processResult(array $data, Message $message, int $initiatorId): bool
	{
		$resultData = $data[self::RESULT_DATA_FIELD] ?? '';
		if (!is_string($resultData))
		{
			(new AutoTaskStatus($message, $initiatorId, Status::NotFound))->send();

			return false;
		}

		(new AutoTaskStatus($message, $initiatorId, Status::ResultCreationStarted, true))->send();

		$added = $this->resultHandler->handle($resultData, $message, $initiatorId);
		if (!$added)
		{
			// the delegate can fail without a terminal status (tasks module off, a chat that is not a
			// task chat, or a SystemException in add); without this the initiator panel hangs forever.
			// On success ResultCreationCompleted is sent by the delegate, so there is no double terminal
			(new AutoTaskStatus($message, $initiatorId, Status::NotFound))->send();
		}

		return $added;
	}
}
