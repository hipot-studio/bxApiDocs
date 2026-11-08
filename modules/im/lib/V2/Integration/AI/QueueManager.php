<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI;

use Bitrix\Im\V2\Integration\AI\Queue\MultipleMessageTaskCreationJob;
use Bitrix\Im\V2\Integration\AI\Queue\SingleMessageTaskCreationJob;
use Bitrix\Im\V2\Integration\AI\Queue\TranscriptionTaskCreationJob;
use Bitrix\Im\V2\Integration\AI\Queue\TranscriptionJob;
use Bitrix\Im\V2\Integration\AI\TaskCreation\SingleMessageCreator;
use Bitrix\Im\V2\Integration\AI\TaskCreation\MultipleMessagesCreator;
use Bitrix\Im\V2\Integration\AI\TaskCreation\TranscriptionCreator;
use Bitrix\Im\V2\Integration\AI\Transcription\TranscribeManager;
use Bitrix\Main\Event;

class QueueManager
{
	public static function onQueueJobExecute(Event $event): void
	{
		/** @var \Bitrix\AI\Engine\IEngine $engine */
		$engine = $event->getParameter('engine');
		$context = $engine->getContext();

		$moduleId = $context->getModuleId();
		$contextId = $context->getContextId();
		$parameters = $context->getParameters();

		if (
			empty($moduleId)
			|| $moduleId != 'im'
		)
		{
			return;
		}

		match (true)
		{
			self::isTranscribeJob($contextId, $parameters) => (new TranscriptionJob($event))->processQueueJob(),
			self::isTaskCreationTranscriptionJob($contextId, $parameters) => (new TranscriptionTaskCreationJob($event))->processQueueJob(),
			self::isTaskCreationMessageJob($contextId, $parameters) => (new SingleMessageTaskCreationJob($event))->processQueueJob(),
			self::isTaskCreationMultiMessageJob($contextId, $parameters) => (new MultipleMessageTaskCreationJob($event))->processQueueJob(),
			default => null,
		};
	}

	public static function onQueueJobFail(Event $event): void
	{
		/** @var \Bitrix\AI\Engine\IEngine $engine */
		$engine = $event->getParameter('engine');
		$context = $engine->getContext();

		$moduleId = $context->getModuleId();
		$contextId = $context->getContextId();
		$parameters = $context->getParameters();

		if (
			empty($moduleId)
			|| $moduleId != 'im'
		)
		{
			return;
		}

		match (true)
		{
			self::isTranscribeJob($contextId, $parameters) => (new TranscriptionJob($event))->processFailedJob(),
			self::isTaskCreationTranscriptionJob($contextId, $parameters) => (new TranscriptionTaskCreationJob($event))->processFailedJob(),
			self::isTaskCreationMessageJob($contextId, $parameters) => (new SingleMessageTaskCreationJob($event))->processFailedJob(),
			self::isTaskCreationMultiMessageJob($contextId, $parameters) => (new MultipleMessageTaskCreationJob($event))->processFailedJob(),
			default => null,
		};
	}

	private static function isTranscribeJob(string $contextId, mixed $parameters): bool
	{
		if (
			empty($contextId)
			|| $contextId !== TranscribeManager::CONTEXT_ID
			|| empty($parameters)
			|| empty($parameters['fileId'])
			|| empty($parameters['chatId'])
			|| empty($parameters['diskFileId'])
			|| empty($parameters['messageId'])
		)
		{
			return false;
		}

		return true;
	}

	private static function isTaskCreationTranscriptionJob(string $contextId, mixed $parameters): bool
	{
		if (
			empty($contextId)
			|| $contextId !== TranscriptionCreator::CONTEXT_ID
			|| empty($parameters)
			|| empty($parameters['fileId'])
			|| empty($parameters['diskFileId'])
			|| empty($parameters['messageId'])
		)
		{
			return false;
		}

		return true;
	}

	private static function isTaskCreationMessageJob(string $contextId, mixed $parameters): bool
	{
		if (
			empty($contextId)
			|| $contextId !== SingleMessageCreator::CONTEXT_ID
			|| empty($parameters)
			|| empty($parameters['messageId'])
			|| empty($parameters['userId'])
		)
		{
			return false;
		}

		return true;
	}

	private static function isTaskCreationMultiMessageJob(string $contextId, mixed $parameters): bool
	{
		if (
			empty($contextId)
			|| $contextId !== MultipleMessagesCreator::CONTEXT_ID
			|| empty($parameters)
			|| empty($parameters['messageIds'])
		)
		{
			return false;
		}

		return true;
	}
}
