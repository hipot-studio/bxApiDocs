<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\TaskCreation;

use Bitrix\AI\Context;
use Bitrix\Im\V2\Entity\File\FileItem;
use Bitrix\Im\V2\Integration\AI\CopilotError;
use Bitrix\Im\V2\Integration\AI\Transcription\LaunchType;
use Bitrix\Im\V2\Integration\AI\Transcription\Result\TranscribeResult;
use Bitrix\Im\V2\Integration\AI\Transcription\TranscribeManager;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Result;

class TranscriptionCreator extends AbstractCreator
{
	public const CONTEXT_ID = 'chat_task_creation_from_transcription';

	protected int $fileId;
	protected int $diskFileId;

	public function __construct(Message $message, int $fileId, int $diskFileId)
	{
		$this->message = $message;
		$this->fileId = $fileId;
		$this->diskFileId = $diskFileId;
	}

	public function sendAiQuery(): Result
	{
		$file = $this->message->getFiles()->getAny();
		$transcriptText = $file->getCompletedTranscription()?->getPlainText();

		if ($transcriptText !== null)
		{
			return $this->sendReadyTranscriptQuery($transcriptText);
		}

		if ($file->isTranscribable())
		{
			return $this->launchTranscription($file);
		}

		return (new Result())->addError(new CopilotError(CopilotError::TRANSCRIPTION_SERVICE_ERROR));
	}

	/**
	 * Starts the transcription this launch waits for.
	 */
	private function launchTranscription(FileItem $file): Result
	{
		$transcribeManager = new TranscribeManager(
			$file->getOriginalFileId(),
			$file->getId(),
			$this->message->getChatId(),
			$this->message->getMessageId()
		);

		$userId = $this->getContext()->getUserId();

		// the window is snapshotted now and replayed when the transcription comes back: by then
		// the neighbourhood has moved on, and the model must see what the user saw.
		//
		// The snapshot goes in as a provider and not as a ready array: taking it selects the
		// neighbourhood, while transcribeFile() short-circuits whenever this file already has a
		// transcription or is being transcribed by another flow - and this branch has no dedup slot
		// of its own, so a repeated click reaches it every time.
		return $this->reportTranscriptionLaunch($transcribeManager->transcribeFile(
			LaunchType::Manual,
			['userId' => $userId],
			fn (): array => $this->buildContextBoundsSnapshot($userId)
		));
	}

	/**
	 * Whether the launch may report success to the initiator.
	 *
	 * TranscribeManager short-circuits when the file is already being transcribed by another flow
	 * and returns a successful Result without queueing a job of ours. That pending job belongs to
	 * an Auto launch and carries no userId, so no callback with the parameters of this user ever
	 * arrives: reporting success would answer {result: true} and then stay silent forever, while a
	 * manual launch must end with a task or with an error. The multiselect flow tells the same case
	 * apart the same way, @see MultipleMessagesCreator::launchTranscriptions().
	 */
	private function reportTranscriptionLaunch(TranscribeResult $result): Result
	{
		if ($result->isSuccess() && !$result->isNewJobQueued())
		{
			return (new Result())->addError(new CopilotError(CopilotError::TRANSCRIPTION_SERVICE_ERROR));
		}

		return $result;
	}

	/**
	 * The transcription is already there, so the click goes straight to the model and takes the
	 * dedup slot of the single-message scenario: a repeated click on the same ready transcription
	 * must not produce a second task. The slot is released by TranscriptionTaskCreationJob.
	 */
	private function sendReadyTranscriptQuery(string $transcriptText): Result
	{
		$messageId = (int)$this->message->getId();

		if (!$this->reserveSingleMessageSlot($messageId))
		{
			return $this->refuseTakenSlot();
		}

		$result = $this->sendAiQueryInternal(
			$transcriptText,
			$this->getContext()->getUserId(),
			LaunchType::Manual
		);

		if (!$result->isSuccess())
		{
			AutoTaskQueueManager::getInstance()->delete([$messageId]);
		}

		return $result;
	}

	public function sendAiQueryInternal(
		string $transcriptText,
		int $userId,
		LaunchType $launchType = LaunchType::Auto
	): Result
	{
		if (!$this->canSendQuery($launchType))
		{
			return new Result();
		}

		$context = new Context(self::MODULE_ID, self::CONTEXT_ID);
		$context->setParameters([
			'fileId' => $this->fileId,
			'diskFileId' => $this->diskFileId,
			'messageId' => (int)$this->message->getId(),
			'launchType' => $launchType->value,
			'userId' => $userId,
		]);

		return $this->dispatchTaskExtractionQuery($context, $transcriptText, $userId, $launchType);
	}

	protected function canSendQuery(LaunchType $launchType): bool
	{
		if ($launchType === LaunchType::Auto && $this->message->getParams()->isSet(Message\Params::FORWARD_ID))
		{
			return false;
		}

		if ($this->isEmptyMessage())
		{
			return false;
		}

		return !$this->isDeniedChatForAutoTask();
	}
}
