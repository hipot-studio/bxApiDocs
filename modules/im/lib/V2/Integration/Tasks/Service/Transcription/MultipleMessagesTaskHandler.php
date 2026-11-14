<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Service\Transcription;

use Bitrix\Im\V2\Integration\AI\TaskCreation\Duplicate\DuplicateCheckService;
use Bitrix\Im\V2\Integration\AI\TaskCreation\Status;
use Bitrix\Im\V2\Integration\AI\Transcription\LaunchType;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Pull\Event\AutoTaskStatus;

/**
 * Creates tasks from several selected messages (AI multiselect, SC-002): validates the
 * AI verdict, reports statuses by the anchor message and delegates the task pipeline to
 * TranscribedTaskHandler, forwarding the whole selection to the task chat.
 *
 * The multiselect flow intentionally supports tasks only: unlike the single-message flow
 * (TranscriptionHandler), an EntityType::Result verdict from the shared prompt never creates
 * a "result" entity here - it degrades to NotFound when launched automatically and to a task
 * built without a verdict when launched manually (ALG-01).
 */
class MultipleMessagesTaskHandler
{
	private const TYPE_FIELD = 'type';
	private const TASK_DATA_FIELD = 'task_data';

	public function __construct(
		private readonly TranscribedTaskHandler $taskHandler,
		private readonly TaskDataExtractor $taskDataExtractor = new TaskDataExtractor(),
		private readonly DuplicateCheckService $duplicateCheckService = new DuplicateCheckService(),
	)
	{
	}

	/**
	 * @param bool $isPartial part of the selection never reached the combined text because its
	 *        transcription was not delivered, so the created task covers only a part of it
	 */
	public function handle(
		array $data,
		MessageCollection $messages,
		Message $anchor,
		int $initiatorId,
		LaunchType $launchType = LaunchType::Auto,
		bool $isPartial = false,
		string $sourceText = '',
	): bool
	{
		$verdict = EntityType::tryFrom($data[self::TYPE_FIELD] ?? '');
		$taskData = is_array($data[self::TASK_DATA_FIELD] ?? null) ? $data[self::TASK_DATA_FIELD] : [];

		if ($launchType === LaunchType::Auto && $verdict === EntityType::Task)
		{
			return $this->createTasks(
				$this->taskDataExtractor->extract($data),
				$messages,
				$anchor,
				$initiatorId,
				false,
				$isPartial,
				$sourceText,
			);
		}

		if ($verdict === EntityType::Task && !empty($taskData))
		{
			if ($launchType === LaunchType::Manual)
			{
				// a recognized assignment left without a usable title is still a task the initiator
				// asked for, so the title falls back to the default one instead of a refusal ([P1.T2])
				$taskData = TranscribedTaskHandler::withDefaultTitle($taskData);
				// the multiselect runs on the prompt of the manual launch, the same one the single-message
				// flow runs on, so its two-items rule holds here too. An automatic launch runs on
				// task_extraction_prompt, whose rule about check lists is unknown here
				$taskData = TranscribedTaskHandler::withoutSingleItemCheckLists($taskData);
			}

			return $this->createTasks(
				[$taskData],
				$messages,
				$anchor,
				$initiatorId,
				false,
				$isPartial,
				$sourceText,
			);
		}

		if ($launchType === LaunchType::Auto)
		{
			(new AutoTaskStatus($anchor, $initiatorId, Status::NotFound))->send();

			return false;
		}

		// an automatic launch returned above, so this is a manual one: the check list rule of its
		// prompt holds for a task built without a verdict just as it does for a recognized one
		$taskData = TranscribedTaskHandler::buildFallbackTaskData($taskData);
		$taskData = TranscribedTaskHandler::withoutSingleItemCheckLists($taskData);

		return $this->createTasks(
			[$taskData],
			$messages,
			$anchor,
			$initiatorId,
			true,
			$isPartial,
			$sourceText,
		);
	}

	private function createTasks(
		array $tasksData,
		MessageCollection $messages,
		Message $anchor,
		int $initiatorId,
		bool $builtWithoutVerdict,
		bool $isPartial,
		string $sourceText,
	): bool
	{
		if ($tasksData === [])
		{
			(new AutoTaskStatus($anchor, $initiatorId, Status::NotFound))->send();

			return false;
		}

		(new AutoTaskStatus($anchor, $initiatorId, Status::TaskCreationStarted, true))->send();

		$createdCount = 0;
		$deferredCount = 0;
		foreach ($tasksData as $taskData)
		{
			$outcome = $this->taskHandler->handle(
				$taskData,
				$anchor,
				$initiatorId,
				$messages,
				$builtWithoutVerdict,
				$isPartial,
				withTerminalStatus: false,
				sourceText: $sourceText,
			);

			match ($outcome)
			{
				TaskCreationOutcome::Created => $createdCount++,
				TaskCreationOutcome::Deferred => $deferredCount++,
				TaskCreationOutcome::Failed => null,
			};
		}

		if ($deferredCount > 0)
		{
			$this->duplicateCheckService->finalizeBatch($anchor, $initiatorId, $createdCount);

			return true;
		}

		(new AutoTaskStatus(
			$anchor,
			$initiatorId,
			$createdCount > 0 ? Status::TaskCreationCompleted : Status::NotFound,
			true,
			$isPartial,
		))->send();

		return $createdCount > 0;
	}
}
