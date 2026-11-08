<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\Queue;

use Bitrix\AI\Engine\IEngine;
use Bitrix\AI\Result;
use Bitrix\Im\V2\Integration\AI\TaskCreation\AutoTaskQueueManager;
use Bitrix\Im\V2\Integration\AI\TaskCreation\AutoTaskType;
use Bitrix\Im\V2\Integration\AI\TaskCreation\MultipleMessagesCreator;
use Bitrix\Im\V2\Integration\AI\TaskCreation\Status;
use Bitrix\Im\V2\Integration\AI\Transcription\LaunchType;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Pull\Event\AutoTaskStatus;

class MultipleMessageTaskCreationJob extends QueueJob
{
	public function processQueueJob(): void
	{
		/** @var IEngine $engine */
		$engine = $this->event->getParameter('engine');
		$parameters = $engine->getContext()->getParameters();

		$messageIds = $this->extractIntList($parameters, 'messageIds');
		if (empty($messageIds))
		{
			return;
		}

		$userId = (int)($parameters['userId'] ?? 0);
		$queueManager = AutoTaskQueueManager::getInstance();

		// the surviving messages: they carry the task content and get forwarded into its chat.
		// The identity of the batch stays $messageIds - it keys the record and the anchor
		$messages = new MessageCollection($messageIds);

		$result = $this->event->getParameter('result');
		if (!($result instanceof Result))
		{
			$queueManager->delete($messageIds, AutoTaskType::TaskCreationMulti);
			$this->sendNotFound($messageIds, $messages, $userId);

			return;
		}

		try
		{
			if ($messages->isEmpty())
			{
				// every selected message was deleted before the AI response returned: a partly
				// deleted selection keeps going, but here no chat is left to create the task in
				$this->sendNotFound($messageIds, $messages, $userId);

				return;
			}

			$row = $queueManager->getRow($messageIds, AutoTaskType::TaskCreationMulti);
			$creator = new MultipleMessagesCreator(
				$messages,
				$messageIds,
				MultipleMessagesCreator::readExcludedSources($row['params'] ?? null)
			);
			// reuse the combined text the live creator already built (m3); fall back to a fresh
			// build only for records queued by older code without the sourceText parameter
			$sourceText = isset($parameters['sourceText']) && is_string($parameters['sourceText'])
				? $parameters['sourceText']
				: $creator->buildCombinedSourceText();
			$data = $result->getJsonData() ?? [];

			$creator->createTask($data, $sourceText, $userId, LaunchType::Manual);
		}
		finally
		{
			// the dedup record must not outlive the job, even when loading the collection
			// or building the combined text throws - a leaked record blocks the selection
			$queueManager->delete($messageIds, AutoTaskType::TaskCreationMulti);
		}
	}

	public function processFailedJob(): void
	{
		/** @var IEngine $engine */
		$engine = $this->event->getParameter('engine');
		$parameters = $engine->getContext()->getParameters();

		$messageIds = $this->extractIntList($parameters, 'messageIds');
		if (empty($messageIds))
		{
			return;
		}

		$userId = (int)($parameters['userId'] ?? 0);

		AutoTaskQueueManager::getInstance()->delete($messageIds, AutoTaskType::TaskCreationMulti);

		$this->sendNotFound($messageIds, new MessageCollection($messageIds), $userId);
	}

	private function sendNotFound(array $messageIds, MessageCollection $messages, int $userId): void
	{
		if (empty($messageIds) || $userId <= 0)
		{
			return;
		}

		// the anchor comes from the original selection, so the job and the live creator report the
		// terminal status on the same message id even when that message is gone by now
		$anchorMessage = MultipleMessagesCreator::resolveAnchorMessage($messageIds, $messages);

		(new AutoTaskStatus($anchorMessage, $userId, Status::NotFound))->send();
	}
}
