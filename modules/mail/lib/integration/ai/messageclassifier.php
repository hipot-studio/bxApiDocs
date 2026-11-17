<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AI;

use Bitrix\AI\Engine\Engine;
use Bitrix\AI\Result;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Mail\Internal\Async\Message\ClassifyMailMessage;
use Bitrix\Mail\Internal\Service\Message\ClassificationLabel;
use Bitrix\Mail\Internal\Service\Message\ClassificationMessageRepository;
use Bitrix\Mail\Internal\Service\Message\ClassificationService;
use Bitrix\Mail\Internal\Service\Message\ClassificationSettings;
use Bitrix\Mail\Internal\Service\Message\ClassificationTextBuilder;
use Bitrix\Mail\Internal\Service\Message\ClassifyPendingService;

/**
 * Event seam between mail and the ai queue.
 */
class MessageClassifier
{
	/** A label applies only above this probability; the mark storage knows nothing about probabilities. */
	public const CLASSIFICATION_THRESHOLD = 0.6;

	protected static ?ClassificationSettings $settings = null;

	protected static function getClassificationService(): ClassificationService
	{
		return new ClassificationService();
	}

	protected static function getPendingService(): ClassifyPendingService
	{
		return new ClassifyPendingService();
	}

	protected static function getMessageRepository(): ClassificationMessageRepository
	{
		return new ClassificationMessageRepository();
	}

	/** Kept for the whole run: the settings service caches the per-mailbox answer. */
	protected static function getSettings(): ClassificationSettings
	{
		return static::$settings ??= new ClassificationSettings();
	}

	protected static function isAutoClassifyEnabled(): bool
	{
		return static::getSettings()->isAutoClassifyEnabled();
	}

	protected static function isAutoClassifyAllowedForMailbox(int $mailboxId): bool
	{
		return static::getSettings()->isAutoClassifyAllowedForMailbox($mailboxId);
	}

	protected static function isAiModuleAvailable(): bool
	{
		return Loader::includeModule('ai');
	}

	protected static function messageStillExists(int $mailboxId, int $messageId): bool
	{
		return static::getMessageRepository()->exists($mailboxId, $messageId);
	}

	/**
	 * Must not break receiving a letter: Event::send rethrows whatever a handler throws.
	 */
	public static function onMailMessageNew(Event $event): void
	{
		try
		{
			static::handleMailMessageNew($event);
		}
		catch (\Throwable)
		{
		}
	}

	protected static function handleMailMessageNew(Event $event): void
	{
		if (!static::isAutoClassifyEnabled())
		{
			return;
		}

		$message = $event->getParameter('message');
		if (!is_array($message) || !static::isIncoming($message))
		{
			return;
		}

		if (!$event->getParameter('isFreshArrival'))
		{
			return;
		}

		$messageId = (int)($message['ID'] ?? 0);
		$mailboxId = (int)($message['MAILBOX_ID'] ?? 0);
		if ($messageId <= 0 || $mailboxId <= 0)
		{
			return;
		}

		// Every check above reads the loaded message array, so a sync pass without a single candidate pays
		// neither the ai module bootstrap below nor the whitelist query after it.
		if (!static::isAiModuleAvailable())
		{
			return;
		}

		if (!static::isAutoClassifyAllowedForMailbox($mailboxId))
		{
			return;
		}

		if (ClassificationTextBuilder::isBodyDeferred($message))
		{
			static::getPendingService()->mark($mailboxId, $messageId);

			return;
		}

		// Three states, not two: a letter carrying no words would take a slot of the batch and a read of its
		// own, only for the receiver to find nothing to classify.
		if (ClassificationTextBuilder::hasNothingToClassify($message))
		{
			return;
		}

		static::enqueueClassification($mailboxId, $messageId);
	}

	/** Must not break the body sync pass: Event::send rethrows whatever a handler throws. */
	public static function onMailMessageBodySynced(Event $event): void
	{
		try
		{
			static::handleMailMessageBodySynced($event);
		}
		catch (\Throwable)
		{
		}
	}

	protected static function handleMailMessageBodySynced(Event $event): void
	{
		$mailboxId = (int)$event->getParameter('mailboxId');
		$messageId = (int)$event->getParameter('messageId');
		$bodyArrived = (bool)$event->getParameter('bodyArrived');

		if ($mailboxId <= 0 || $messageId <= 0)
		{
			return;
		}

		$pendingService = static::getPendingService();
		if (!$pendingService->isPending($mailboxId, $messageId))
		{
			return;
		}

		// Dropped before any check that may reject the message: this is the only place that drops it for a
		// living letter, and a feature switched off inside the waiting window would keep the flag forever.
		$pendingService->clear($mailboxId, $messageId);

		if (!$bodyArrived)
		{
			return;
		}

		if (!static::isAutoClassifyEnabled())
		{
			return;
		}

		if (!static::isAiModuleAvailable())
		{
			return;
		}

		if (!static::isAutoClassifyAllowedForMailbox($mailboxId))
		{
			return;
		}

		static::enqueueClassification($mailboxId, $messageId);
	}

	/**
	 * Only queued here: mail sync must not wait for the ai queue.
	 */
	protected static function enqueueClassification(int $mailboxId, int $messageId): void
	{
		try
		{
			static::sendClassifyMessage($mailboxId, $messageId);
		}
		catch (\Throwable)
		{
		}
	}

	protected static function sendClassifyMessage(int $mailboxId, int $messageId): void
	{
		(new ClassifyMailMessage($mailboxId, $messageId))->send('mail_message_classify');
	}

	/**
	 * The job belongs to the ai queue: a failure of ours must not be charged to it.
	 */
	public static function onQueueJobExecute(Event $event): void
	{
		try
		{
			static::handleQueueJobExecute($event);
		}
		catch (\Throwable)
		{
		}
	}

	protected static function handleQueueJobExecute(Event $event): void
	{
		$target = static::resolveOwnJob($event);
		if ($target === null)
		{
			return;
		}

		$result = $event->getParameter('result');
		if (!$result instanceof Result)
		{
			return;
		}

		[$mailboxId, $messageId] = $target;

		$rawData = $result->getRawData();
		$labels = TritonMessageClassifier::extractLabels(is_array($rawData) ? $rawData : null);

		// The common outcome is no probability above the threshold: filter first, do not query the letter for nothing
		$labels = array_filter($labels, static fn ($probability) => static::labelApplies($probability));
		if ($labels === [])
		{
			return;
		}

		// Asked again here: a job already taken by the queue outlives the switch by its ttl
		if (!static::isAutoClassifyEnabled() || !static::isAutoClassifyAllowedForMailbox($mailboxId))
		{
			return;
		}

		if (!static::messageStillExists($mailboxId, $messageId))
		{
			return;
		}

		$service = static::getClassificationService();
		try
		{
			foreach (array_keys($labels) as $labelValue)
			{
				$service->add($mailboxId, $messageId, ClassificationLabel::from((string)$labelValue));
			}
		}
		catch (\Throwable)
		{
		}
	}

	/**
	 * The events of ai are shared by every consumer, so a job of ours is told apart by its context.
	 *
	 * @return array{0: int, 1: int}|null Mailbox and message ids, or null when the job is not ours.
	 */
	protected static function resolveOwnJob(Event $event): ?array
	{
		$engine = $event->getParameter('engine');
		if (!$engine instanceof Engine)
		{
			return null;
		}

		$context = $engine->getContext();
		if ($context->getModuleId() !== 'mail' || $context->getContextId() !== TritonMessageClassifier::CONTEXT_ID)
		{
			return null;
		}

		$parameters = $context->getParameters();
		if (!is_array($parameters))
		{
			return null;
		}

		$mailboxId = (int)($parameters[TritonMessageClassifier::PARAM_MAILBOX_ID] ?? 0);
		$messageId = (int)($parameters[TritonMessageClassifier::PARAM_MESSAGE_ID] ?? 0);
		if ($mailboxId <= 0 || $messageId <= 0)
		{
			return null;
		}

		return [$mailboxId, $messageId];
	}

	protected static function labelApplies(float $probability): bool
	{
		return $probability > static::CLASSIFICATION_THRESHOLD;
	}

	protected static function isIncoming(array $message): bool
	{
		return empty($message['IS_OUTCOME'])
			&& empty($message['IS_DRAFT'])
			&& empty($message['IS_TRASH'])
			&& empty($message['IS_SPAM'])
		;
	}
}

