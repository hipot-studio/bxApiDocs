<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\TaskCreation;

use Bitrix\AI\Context;
use Bitrix\Im\V2\Integration\AI\Transcription\LaunchType;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Result;

class SingleMessageCreator extends AbstractCreator
{
	public const CONTEXT_ID = 'chat_task_creation_from_single_message';

	public function __construct(Message $message)
	{
		$this->message = $message;
	}

	public function sendAiQuery(): Result
	{
		$result = new Result();

		if (!$this->canSendQuery())
		{
			return $result->addError(new CreationError(CreationError::AI_TASK_SOURCE_NOT_SUPPORTED));
		}

		$messageId = (int)$this->message->getId();
		$userId = $this->getContext()->getUserId();
		$sourceText = $this->extractSourceText();
		$queueManager = AutoTaskQueueManager::getInstance();

		if (!$this->reserveSingleMessageSlot($messageId))
		{
			return $this->refuseTakenSlot();
		}

		if ($sourceText === '')
		{
			try
			{
				$this->createMinimalTask($userId, LaunchType::Manual);
			}
			finally
			{
				$queueManager->delete([$messageId]);
			}

			return $result;
		}

		$result = $this->sendAiQueryInternal($userId, $sourceText);
		if (!$result->isSuccess())
		{
			$queueManager->delete([$messageId]);
		}

		return $result;
	}

	private function sendAiQueryInternal(int $userId, string $sourceText): Result
	{
		$context = new Context(self::MODULE_ID, self::CONTEXT_ID);
		$context->setParameters([
			'messageId' => (int)$this->message->getId(),
			'userId' => $userId,
		]);

		return $this->dispatchTaskExtractionQuery($context, $sourceText, $userId, LaunchType::Manual);
	}

	public function extractSourceText(): string
	{
		return $this->buildSourceLine($this->message, self::extractSourceTextFrom($this->message));
	}

	public function isMessageDeleted(): bool
	{
		return $this->isDeletedMessage($this->message);
	}

	protected function hasMinimalTaskContent(): bool
	{
		return $this->messageHasMinimalTaskContent($this->message);
	}

	/**
	 * Tells an unsupported source (which the caller reports synchronously) from a source without
	 * text: the latter is not a refusal, it degrades to createMinimalTask().
	 */
	protected function canSendQuery(): bool
	{
		if ($this->isEmptyMessage() || $this->isContentlessMessage($this->message))
		{
			return false;
		}

		return !$this->isDeniedChatForAutoTask();
	}
}
