<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI;

use Bitrix\Im\V2\Integration\AI\Queue\TaskCreationJob;
use Bitrix\Im\V2\Integration\AI\Queue\TranscriptionJob;
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
			self::isTaskCreationJob($contextId, $parameters) => (new TaskCreationJob($event))->processQueueJob(),
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
			self::isTaskCreationJob($contextId, $parameters) => (new TaskCreationJob($event))->processFailedJob(),
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

	private static function isTaskCreationJob(string $contextId, mixed $parameters): bool
	{
		if (
			empty($contextId)
			|| $contextId !== TaskCreationManager::CONTEXT_ID
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
}
