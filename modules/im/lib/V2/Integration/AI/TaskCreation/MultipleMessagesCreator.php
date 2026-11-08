<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\TaskCreation;

use Bitrix\AI\Context;
use Bitrix\Im\V2\Entity\File\FileItem;
use Bitrix\Im\V2\Integration\AI\Transcription\LaunchType;
use Bitrix\Im\V2\Integration\AI\Transcription\TranscribeManager;
use Bitrix\Im\V2\Integration\Tasks\Service\Transcription\MultipleMessagesTaskHandler;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Pull\Event\AutoTaskStatus;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;

class MultipleMessagesCreator extends AbstractCreator
{
	public const CONTEXT_ID = 'chat_task_creation_from_multiple_messages';

	/** @see MultipleMessagesCreator::addExcludedSource() */
	public const EXCLUSION_REASON_TRANSCRIPTION_FAILED = 'transcriptionFailed';
	public const EXCLUSION_REASON_MESSAGE_UNAVAILABLE = 'messageUnavailable';

	private const LOCK_TIMEOUT = 3;
	private const AUTO_TASK_TYPE = AutoTaskType::TaskCreationMulti;

	private MessageCollection $messages;

	/**
	 * The selection registered in the queue record: it keys the record, the lock and every
	 * callback, and it resolves the anchor. Excluding a source never shrinks it.
	 *
	 * @var int[]
	 */
	private array $sourceMessageIds;

	/** @var array<int, array{messageId: int, fileId: int|null, reason: string}> */
	private array $excludedSources;

	/** @see self::preloadSelection() */
	private bool $isSelectionPreloaded = false;

	/**
	 * @param int[]|null $sourceMessageIds identity of the batch, defaults to the selection being
	 *        registered; the queue callbacks pass back the set the record was registered with,
	 *        which may be wider than the collection they managed to reload
	 * @param array<int, array{messageId: int, fileId: int|null, reason: string}> $excludedSources
	 */
	public function __construct(
		MessageCollection $messages,
		?array $sourceMessageIds = null,
		array $excludedSources = []
	)
	{
		// collection entries are keyed by message id and the combined text must follow the chat
		// order, so sort once here; the batch loads the scans need are lazy, @see self::preloadSelection()
		$messages->ksort();

		$this->messages = $messages;
		$this->sourceMessageIds = self::normalizeIds($sourceMessageIds ?? $messages->getIds());
		$this->excludedSources = $excludedSources;
		$this->message = self::resolveAnchorMessage($this->sourceMessageIds, $messages);
	}

	/**
	 * True when the task was built without part of the selection because a transcription never
	 * arrived. An unavailable message alone does not make the result partial: it is not explained
	 * to the user either.
	 */
	public function isPartialResult(): bool
	{
		return self::hasFailedTranscription($this->excludedSources);
	}

	public function sendAiQuery(): Result
	{
		$result = new Result();

		if (!$this->canSendQuery())
		{
			return $result;
		}

		$messageIds = $this->sourceMessageIds;
		$userId = $this->getContext()->getUserId();
		$queueManager = AutoTaskQueueManager::getInstance();

		// cheap dedup pre-check, so duplicate clicks skip the selection scan below
		if ($queueManager->inQueue($messageIds, self::AUTO_TASK_TYPE))
		{
			return $this->refuseTakenSlot();
		}

		$pendingTargets = $this->getPendingTranscriptionTargets();

		if (!$this->registerBatch($queueManager, $messageIds, array_keys($pendingTargets)))
		{
			return $this->refuseTakenSlot();
		}

		if (empty($pendingTargets))
		{
			return $this->sendCombinedQuery($userId);
		}

		// the search status must precede launchTranscriptions: a fully short-circuited
		// batch advances to the combined query (or a terminal status) synchronously,
		// and a later Search would reopen the magic panel after its terminal status
		(new AutoTaskStatus($this->message, $userId, Status::Search))->send();

		$this->launchTranscriptions($pendingTargets, $messageIds, $userId);

		return $result;
	}

	public function createTask(
		array $data,
		string $sourceText,
		int $userId,
		LaunchType $launchType = LaunchType::Auto
	): void
	{
		if ($this->isEmptyMessage())
		{
			$this->reportLostSource($userId, $launchType);

			return;
		}

		// the selection is forwarded into the chat of the task, and the queue job may reach this
		// without any of the scans - it reuses the combined text the live creator built
		$this->preloadSelection();

		Locator::getContext()->setUser($userId);

		/** @var MultipleMessagesTaskHandler $handler */
		$handler = ServiceLocator::getInstance()->get(MultipleMessagesTaskHandler::class);
		$handler->handle(
			$data,
			$this->getMessagesToForward(),
			$this->message,
			$userId,
			$launchType,
			$this->isPartialResult()
		);
	}

	/**
	 * A soft-deleted message carries the "message deleted" placeholder instead of its text, so it
	 * is left out of the forwarding just as it is left out of the sources (AC-074). The selection
	 * itself keeps every id: it identifies the batch. An empty result is a legitimate one - the
	 * handler tells the multiselect flow from the single-message one by a collection being passed
	 * at all, not by its size.
	 */
	private function getMessagesToForward(): MessageCollection
	{
		return $this->messages->filter(fn (Message $message) => !$this->isDeletedMessage($message));
	}

	public function buildCombinedSourceText(): string
	{
		$this->preloadSelection();

		$parts = [];
		$usedTranscriptions = [];

		foreach ($this->messages as $message)
		{
			if ($this->isContentlessMessage($message))
			{
				continue;
			}

			$text = self::extractSourceTextFrom($message);
			if ($text !== '')
			{
				$parts[] = $this->buildSourceLine($message, $text);
			}

			$fileItem = $this->getTranscribableFile($message);
			if ($fileItem === null || isset($usedTranscriptions[$fileItem->getOriginalFileId()]))
			{
				continue;
			}

			$usedTranscriptions[$fileItem->getOriginalFileId()] = true;

			$transcriptText = trim((string)($fileItem->getCompletedTranscription()?->getPlainText() ?? ''));
			if ($transcriptText !== '')
			{
				$parts[] = $this->buildSourceLine($message, $transcriptText);
			}
		}

		return trim(implode("\n", $parts));
	}

	/**
	 * Reacts to one finished (successfully or not) batch transcription: removes the file
	 * from the pending set and, once the batch has no pending transcriptions left, fires
	 * the combined AI query for the whole selection (SC-002, AC-029).
	 *
	 * A failed transcription ($failed) makes its own source unusable, not the whole batch:
	 * the source is marked excluded in the record params and the rest of the selection still
	 * feeds the task. The batch is aborted only when nothing usable is left.
	 *
	 * Static on purpose: the partial-callback path only needs the message ids and the
	 * finished file id, so the whole MessageCollection is loaded only once the batch
	 * is actually drained (or a source has to be excluded).
	 *
	 * The entry point of an asynchronous callback, which brings one finished file at a time. A
	 * caller that already holds a whole set of them drains it in one pass, @see
	 * self::drainPendingFiles().
	 *
	 * @param int[] $messageIds the selection the record was registered with, never the survivors
	 * @param int|null $sourceMessageId owner of the finished file when the caller already knows it
	 */
	public static function onTranscriptionFinished(
		array $messageIds,
		int $fileId,
		int $userId,
		bool $failed = false,
		?int $sourceMessageId = null
	): void
	{
		// resolving the owner of a failed file needs the collection: doing it before the lock
		// keeps the critical section as short as the sibling callbacks of this batch expect
		$failedSourceId = $failed
			? ($sourceMessageId ?? self::resolveMessageIdByFile($messageIds, $fileId))
			: 0
		;

		self::drainPendingFiles(
			$messageIds,
			[$fileId => ['failed' => $failed, 'messageId' => $failedSourceId]],
			$userId
		);
	}

	/**
	 * Accounts for a whole set of finished transcriptions at once: one lock, one read of the
	 * record, one write of its params. Draining file by file would take the same lock and rewrite
	 * the same row once per file, and only the last of those writes is ever read.
	 *
	 * The outcome is the one a file-by-file drain arrives at: the pending set loses exactly the
	 * files that were still in it, every failed one of them excludes its source, and the batch
	 * advances once - after the lock is released, and only when nothing is pending anymore.
	 *
	 * @param int[] $messageIds the selection the record was registered with, never the survivors
	 * @param array<int, array{failed: bool, messageId: int}> $finishedFiles keyed by original file
	 *        id; messageId is the owner of a failed file and is ignored for a successful one
	 */
	private static function drainPendingFiles(array $messageIds, array $finishedFiles, int $userId): void
	{
		if (empty($finishedFiles))
		{
			return;
		}

		$queueManager = AutoTaskQueueManager::getInstance();
		$lockName = self::buildLockName($messageIds);

		if (!Application::getConnection()->lock($lockName, self::LOCK_TIMEOUT))
		{
			// proceeding without the lock could lose a concurrent advance or fire the combined query
			// twice, and these files come back no other way: they stay in the pending set of the
			// record, and a batch with a pending file neither advances nor closes. So the operation
			// ends here instead - a terminal status, even a duplicate one, costs the initiator less
			// than an indication that hangs until the record expires
			self::abortBatchByIds($messageIds, $userId);

			return;
		}

		$sourceMessageIds = [];
		$excludedSources = [];
		try
		{
			$row = $queueManager->getRow($messageIds, self::AUTO_TASK_TYPE);
			$params = $row['params'] ?? null;
			if ($params === null)
			{
				return;
			}

			$pendingBefore = array_map('intval', $params['pendingFileIds'] ?? []);
			$accounted = array_intersect_key($finishedFiles, array_flip($pendingBefore));
			if (empty($accounted))
			{
				// every one of these files is already accounted for (duplicate or late callback);
				// falling through here would fire the combined query a second time
				return;
			}

			$sourceMessageIds = self::readSourceMessageIds($params, $messageIds);
			$excludedSources = self::readExcludedSources($params);
			$pending = array_values(array_diff($pendingBefore, array_keys($accounted)));

			foreach ($accounted as $fileId => $finishedFile)
			{
				if ($finishedFile['failed'])
				{
					$excludedSources = self::addExcludedSource(
						$excludedSources,
						$finishedFile['messageId'],
						(int)$fileId,
						self::EXCLUSION_REASON_TRANSCRIPTION_FAILED
					);
				}
			}

			$queueManager->updateParamsById(
				$row['id'],
				self::buildParams($sourceMessageIds, $pending, $excludedSources)
			);

			if (!empty($pending))
			{
				return;
			}
		}
		finally
		{
			Application::getConnection()->unlock($lockName);
		}

		self::advanceDrainedBatch($sourceMessageIds, $excludedSources, $userId);
	}

	/**
	 * Every expected transcription is accounted for: reload the selection, exclude the messages
	 * that disappeared meanwhile and either fire the combined query or close the operation.
	 *
	 * Runs outside the batch lock on purpose - it dispatches the AI query. No sibling callback can
	 * interfere anymore: with an empty pending set every later callback stops at the pending check.
	 */
	private static function advanceDrainedBatch(array $sourceMessageIds, array $excludedSources, int $userId): void
	{
		$messages = new MessageCollection($sourceMessageIds);
		$excludedAfterReload = self::excludeUnavailableSources($excludedSources, $sourceMessageIds, $messages);

		if (count($excludedAfterReload) !== count($excludedSources))
		{
			self::persistExcludedSources($sourceMessageIds, $excludedAfterReload);
		}

		if (self::hasEligibleSource($sourceMessageIds, $excludedAfterReload))
		{
			(new self($messages, $sourceMessageIds, $excludedAfterReload))->sendCombinedQuery($userId);

			return;
		}

		// Nothing usable is left. With a failed transcription there is no content to build the task
		// from; without one every selected message is gone, so there is no chat to create it in
		// either - both ways the only possible outcome is the terminal status.
		self::abortBatchByIds($sourceMessageIds, $userId, $messages);
	}

	/**
	 * The selection is intact only when every originally selected message is still available.
	 * A fully deleted message drops out of the reloaded MessageCollection, so a shorter collection
	 * means at least one message disappeared and has to be excluded from the sources and from the
	 * forwarding. Soft-delete does not break the batch (decision 2026-06-25) - the row is still
	 * there and the rest of the selection feeds the task - but such a message keeps neither its
	 * place among the sources nor its place in the forwarding, @see self::getMessagesToForward()
	 */
	public static function isSelectionComplete(array $expectedIds, MessageCollection $messages): bool
	{
		return $messages->count() === count(array_unique(array_map('intval', $expectedIds)));
	}

	/**
	 * Aborts the batch from a static context (transcription barrier / queue job): deletes the
	 * dedup record and sends the initiator a terminal status, so the magic panel closes.
	 */
	public static function abortBatchByIds(array $messageIds, int $userId, ?MessageCollection $messages = null): void
	{
		AutoTaskQueueManager::getInstance()->delete($messageIds, self::AUTO_TASK_TYPE);

		if ($userId <= 0)
		{
			return;
		}

		(new AutoTaskStatus(self::resolveAnchorMessage($messageIds, $messages), $userId, Status::NotFound))->send();
	}

	/**
	 * The anchor (the newest message of the original selection) keys all pull statuses, the
	 * chat-task link and the notification. Both clients compute it the same way before sending
	 * the request and silently drop events addressed to any other message, so it must never be
	 * recomputed from the surviving messages.
	 *
	 * @param int[] $messageIds the selection the batch was registered with
	 */
	public static function resolveAnchorMessageId(array $messageIds): int
	{
		return empty($messageIds) ? 0 : (int)max($messageIds);
	}

	/**
	 * Registers the dedup/batch-state record for this selection under a connection lock.
	 * Returns false when a concurrent attempt for the same selection already owns it.
	 */
	private function registerBatch(AutoTaskQueueManager $queueManager, array $messageIds, array $pendingFileIds): bool
	{
		if (!Application::getConnection()->lock($this->getLockName(), self::LOCK_TIMEOUT))
		{
			// a concurrent attempt for the same selection holds the lock - let it finish
			return false;
		}

		try
		{
			if ($queueManager->inQueue($messageIds, self::AUTO_TASK_TYPE))
			{
				return false;
			}

			$queueManager->add(
				$messageIds,
				self::AUTO_TASK_TYPE,
				self::buildParams($messageIds, $pendingFileIds, [])
			);

			return true;
		}
		finally
		{
			Application::getConnection()->unlock($this->getLockName());
		}
	}

	/**
	 * Builds the combined AI query from all selected messages (texts + ready transcriptions)
	 * and queues it for the engine. Aborts the batch on synchronous failure.
	 */
	private function sendCombinedQuery(int $userId): Result
	{
		$result = new Result();

		Locator::getContext()->setUser($userId);

		$sourceText = $this->buildCombinedSourceText();

		if ($sourceText === '')
		{
			$this->createMinimalTask($userId, LaunchType::Manual);
			AutoTaskQueueManager::getInstance()->delete($this->sourceMessageIds, self::AUTO_TASK_TYPE);

			return $result;
		}

		$context = new Context(self::MODULE_ID, self::CONTEXT_ID);
		$context->setParameters([
			// the identity of the batch, not the survivors: the queue job reads the record and
			// deletes it by this very set, and it addresses its terminal status by its anchor
			'messageIds' => $this->sourceMessageIds,
			'userId' => $userId,
			'sourceText' => $sourceText,
		]);

		$result = $this->dispatchTaskExtractionQuery($context, $sourceText, $userId);

		if (!$result->isSuccess())
		{
			$this->abortBatch($userId);
		}

		return $result;
	}

	/**
	 * After the barrier the batch runs in a queue callback where the returned Result has
	 * no reader, so on failure the initiator must get a terminal pull status - otherwise
	 * the magic panel waits forever.
	 */
	private function abortBatch(int $userId): void
	{
		self::abortBatchByIds($this->sourceMessageIds, $userId, $this->messages);
	}

	protected function hasMinimalTaskContent(): bool
	{
		foreach ($this->messages as $message)
		{
			if ($this->messageHasMinimalTaskContent($message))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, array{message: Message, fileItem: FileItem}> $pendingTargets keyed by original file id
	 */
	private function launchTranscriptions(array $pendingTargets, array $messageIds, int $userId): void
	{
		$skipped = [];

		foreach ($pendingTargets as $originalFileId => $target)
		{
			$fileItem = $target['fileItem'];
			$message = $target['message'];

			$transcribeManager = new TranscribeManager(
				$fileItem->getOriginalFileId(),
				$fileItem->getDiskFileId(),
				(int)$message->getChatId(),
				(int)$message->getMessageId(),
			);

			$transcribeResult = $transcribeManager->transcribeFile(
				LaunchType::Manual,
				[
					'multiMessageIds' => $messageIds,
					'userId' => $userId,
				]
			);

			if (!$transcribeResult->isNewJobQueued())
			{
				// A short-circuit means the transcription is already complete or owned by another
				// flow (isSuccess), or it failed synchronously (!isSuccess). A synchronous failure
				// leaves that source without content, exactly like an async transcription failure.
				$skipped[(int)$originalFileId] = [
					'failed' => !$transcribeResult->isSuccess(),
					'messageId' => (int)$message->getMessageId(),
				];
			}
		}

		// No queue callback with our batch context will arrive for these files. Advance the barrier
		// right away, otherwise it would wait until the TTL. A file owned by another flow joins the
		// combined text only when its transcript lands before the batch drains - an accepted
		// limitation (SDD Q-1).
		//
		// The whole set goes in one pass: every one of these files belongs to the same record under
		// the same lock, and their owners are already known, so nothing here needs a round of its own.
		self::drainPendingFiles($messageIds, $skipped, $userId);
	}

	/**
	 * @return array<int, array{message: Message, fileItem: FileItem}> keyed by original file id
	 */
	private function getTranscribableTargets(): array
	{
		$this->preloadSelection();

		$targets = [];

		foreach ($this->messages as $message)
		{
			$fileItem = $this->getTranscribableFile($message);
			if ($fileItem === null)
			{
				continue;
			}

			$targets[$fileItem->getOriginalFileId()] = [
				'message' => $message,
				'fileItem' => $fileItem,
			];
		}

		return $targets;
	}

	/**
	 * Transcribable files that do not have a completed transcription yet.
	 *
	 * @return array<int, array{message: Message, fileItem: FileItem}> keyed by original file id
	 */
	private function getPendingTranscriptionTargets(): array
	{
		return array_filter(
			$this->getTranscribableTargets(),
			static fn (array $target) => $target['fileItem']->getCompletedTranscription() === null
		);
	}

	/**
	 * A selected message is transcribable only when it is a voice note or a video note
	 * (same rule the CreatorFactory uses to route the single-message transcription flow).
	 * Such a message always carries exactly one file — its note. The isTranscribable()
	 * check carries the size/type limits the single flow enforces before transcription;
	 * files that fail it degrade to plain attachments instead of wasting an AI request.
	 */
	private function getTranscribableFile(Message $message): ?FileItem
	{
		if (!$message->isVoiceNote() && !$message->isVideoNote())
		{
			return null;
		}

		$fileItem = $message->getFiles()->getAny();
		if ($fileItem === null || !$fileItem->isTranscribable())
		{
			return null;
		}

		return $fileItem;
	}

	/**
	 * Params, files and completed transcriptions of the whole selection, in batch and once: the
	 * scans read them per message, so without this warm-up every message would query for its own.
	 * Two to four queries, and none of them for a selection without files.
	 *
	 * Lazy on purpose, and called by every consumer of the prepared collection. A repeated click
	 * over the same selection is refused by the dedup pre-check of sendAiQuery() before a single
	 * scan runs, and it used to pay these queries in the constructor regardless. The background
	 * paths (advanceDrainedBatch, MultipleMessageTaskCreationJob) always need the selection
	 * prepared, and they reach it through those same consumers.
	 */
	private function preloadSelection(): void
	{
		if ($this->isSelectionPreloaded)
		{
			return;
		}

		$this->isSelectionPreloaded = true;

		$this->messages->fillParams();
		$this->messages->getFiles()->fillTranscriptions();
	}

	protected function canSendQuery(): bool
	{
		return !$this->isEmptyMessage() && !$this->isDeniedChatForAutoTask();
	}

	private function getLockName(): string
	{
		return self::buildLockName($this->sourceMessageIds);
	}

	private static function buildLockName(array $messageIds): string
	{
		return 'im_auto_task_creation_multi_' . self::AUTO_TASK_TYPE->buildEntityId($messageIds);
	}

	/**
	 * Resolves the anchor message of the batch. The id always comes from the original selection;
	 * the loaded collection is only a source of an already-hydrated instance, so an excluded or
	 * deleted anchor still addresses the events the clients are waiting for.
	 *
	 * @param int[] $sourceMessageIds the selection the batch was registered with
	 */
	public static function resolveAnchorMessage(array $sourceMessageIds, ?MessageCollection $messages = null): Message
	{
		$anchorId = self::resolveAnchorMessageId($sourceMessageIds);

		if ($messages !== null && isset($messages[$anchorId]))
		{
			return $messages[$anchorId];
		}

		// loading is best effort: the row may be gone, and a failed load leaves the message
		// without its id, while the id is exactly what addresses every event of the operation
		$anchor = (new Message($anchorId))->setMessageId($anchorId);
		$survivedChatId = (int)($messages?->getAny()?->getChatId() ?? 0);

		if ($anchor->getChatId() === null && $survivedChatId > 0)
		{
			// the newest selected message is gone: its id still addresses every event of the
			// operation, but the chat behind the task has to come from a message that survived
			$anchor->setChatId($survivedChatId);
		}

		return $anchor;
	}

	/**
	 * Single owner of the batch record params format: every writer goes through here, so the
	 * shape a reader may rely on is declared in exactly one place.
	 *
	 * @param int[] $sourceMessageIds
	 * @param int[] $pendingFileIds
	 * @param array<int, array{messageId: int, fileId: int|null, reason: string}> $excludedSources
	 */
	private static function buildParams(array $sourceMessageIds, array $pendingFileIds, array $excludedSources): array
	{
		return [
			'pendingFileIds' => array_values(array_map('intval', $pendingFileIds)),
			'sourceMessageIds' => self::normalizeIds($sourceMessageIds),
			'excludedSources' => array_values($excludedSources),
		];
	}

	/**
	 * Records written before this field existed carry the identity only in the callback arguments,
	 * so an absent list falls back to the set the caller was registered with.
	 *
	 * @param int[] $fallbackIds
	 * @return int[]
	 */
	public static function readSourceMessageIds(?array $params, array $fallbackIds): array
	{
		$storedIds = $params['sourceMessageIds'] ?? null;
		$sourceMessageIds = is_array($storedIds) ? self::normalizeIds($storedIds) : [];

		return empty($sourceMessageIds) ? self::normalizeIds($fallbackIds) : $sourceMessageIds;
	}

	/**
	 * An absent list means nothing was excluded - that is how records written before this field
	 * existed keep working.
	 *
	 * @return array<int, array{messageId: int, fileId: int|null, reason: string}>
	 */
	public static function readExcludedSources(?array $params): array
	{
		$excludedSources = [];
		$storedSources = $params['excludedSources'] ?? null;

		foreach (is_array($storedSources) ? $storedSources : [] as $excludedSource)
		{
			if (!is_array($excludedSource) || !isset($excludedSource['reason']))
			{
				continue;
			}

			$fileId = $excludedSource['fileId'] ?? null;
			$excludedSources[] = [
				'messageId' => (int)($excludedSource['messageId'] ?? 0),
				'fileId' => $fileId === null ? null : (int)$fileId,
				'reason' => (string)$excludedSource['reason'],
			];
		}

		return $excludedSources;
	}

	/**
	 * The partial result is computed, never stored: only a transcription that never arrived is
	 * explained to the task chat, an unavailable message is not.
	 *
	 * @param array<int, array{messageId: int, fileId: int|null, reason: string}> $excludedSources
	 */
	public static function hasFailedTranscription(array $excludedSources): bool
	{
		foreach ($excludedSources as $excludedSource)
		{
			if (($excludedSource['reason'] ?? '') === self::EXCLUSION_REASON_TRANSCRIPTION_FAILED)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param int[] $sourceMessageIds
	 * @param array<int, array{messageId: int, fileId: int|null, reason: string}> $excludedSources
	 */
	public static function hasEligibleSource(array $sourceMessageIds, array $excludedSources): bool
	{
		$excludedIds = array_map('intval', array_column($excludedSources, 'messageId'));

		return !empty(array_diff(self::normalizeIds($sourceMessageIds), $excludedIds));
	}

	/**
	 * @param array<int, array{messageId: int, fileId: int|null, reason: string}> $excludedSources
	 * @return array<int, array{messageId: int, fileId: int|null, reason: string}>
	 */
	private static function addExcludedSource(
		array $excludedSources,
		int $messageId,
		?int $fileId,
		string $reason
	): array
	{
		foreach ($excludedSources as $excludedSource)
		{
			if ($excludedSource['messageId'] === $messageId)
			{
				return $excludedSources;
			}
		}

		$excludedSources[] = [
			'messageId' => $messageId,
			'fileId' => $fileId,
			'reason' => $reason,
		];

		return $excludedSources;
	}

	/**
	 * @param array<int, array{messageId: int, fileId: int|null, reason: string}> $excludedSources
	 * @return array<int, array{messageId: int, fileId: int|null, reason: string}>
	 */
	private static function excludeUnavailableSources(
		array $excludedSources,
		array $sourceMessageIds,
		MessageCollection $messages
	): array
	{
		if (self::isSelectionComplete($sourceMessageIds, $messages))
		{
			return $excludedSources;
		}

		// a set and not a list: the loop below asks about every id of the original selection
		$survived = array_flip(array_map('intval', $messages->getIds()));

		foreach (self::normalizeIds($sourceMessageIds) as $messageId)
		{
			if (!isset($survived[$messageId]))
			{
				$excludedSources = self::addExcludedSource(
					$excludedSources,
					$messageId,
					null,
					self::EXCLUSION_REASON_MESSAGE_UNAVAILABLE
				);
			}
		}

		return $excludedSources;
	}

	/**
	 * Re-reads the record instead of reusing the id captured under the lock: by the time the batch
	 * drains the record may already be gone, and updating it by a stale id would resurrect nothing.
	 */
	private static function persistExcludedSources(array $sourceMessageIds, array $excludedSources): void
	{
		$queueManager = AutoTaskQueueManager::getInstance();
		$row = $queueManager->getRow($sourceMessageIds, self::AUTO_TASK_TYPE);

		if ($row === null)
		{
			return;
		}

		$queueManager->updateParamsById($row['id'], self::buildParams($sourceMessageIds, [], $excludedSources));
	}

	/**
	 * Maps a finished transcription back to its source message. Only the failure path needs it,
	 * so the collection is loaded here instead of being carried through every callback.
	 */
	private static function resolveMessageIdByFile(array $messageIds, int $fileId): int
	{
		$messages = new MessageCollection($messageIds);
		$messages->fillFiles();

		foreach ($messages as $message)
		{
			foreach ($message->getFiles() as $fileItem)
			{
				if ($fileItem->getOriginalFileId() === $fileId)
				{
					return (int)$message->getMessageId();
				}
			}
		}

		return 0;
	}

	/**
	 * @return int[]
	 */
	private static function normalizeIds(array $messageIds): array
	{
		return array_values(array_unique(array_map('intval', $messageIds)));
	}
}
