<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Message\Conversation;

use Bitrix\Mail\Helper\MessageConversationStepper;
use Bitrix\Mail\Internal\Repository\MessageConversationListRepository;
use Bitrix\Mail\Internal\Repository\MessageConversationRepository;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Result;

/**
 * Keeps the conversation projection in step with the closure.
 *
 * Three callers use it. The synchronization of a letter projects that one letter as soon as its
 * closure is saved. The background pass of {@see \Bitrix\Mail\Helper\MessageConversationStepper}
 * walks a whole mailbox in portions and decides whether it may group its list. The deletion paths of
 * a letter and of a mailbox drop what the projection must not keep.
 *
 * Nothing here ever throws: the callers are the central write path of the module, and a read model
 * must not be able to break the delivery of mail. A failure is recorded instead, and readiness is
 * taken back; the list then falls back to the plain one, which is the honest answer while the
 * projection is behind. Hiding an unprojected letter from a grouped list would not be.
 *
 * Every method is called outside a transaction on purpose: {@see ConversationIndexer} owns its own
 * one and its retry of a unique conflict is built on that.
 */
class ConversationBackfill
{
	/** Messages one portion of the pass accounts for. */
	public const PORTION_SIZE = 100;

	private const UNEXPECTED_ERROR = 'MAIL_CONVERSATION_UNEXPECTED_FAILURE';
	private const MESSAGE_GONE = 0;

	public function __construct(
		private readonly ConversationIndexer $indexer = new ConversationIndexer(),
		private readonly MessageConversationRepository $repository = new MessageConversationRepository(),
		private readonly MessageConversationListRepository $listRepository = new MessageConversationListRepository(),
		private readonly ConversationDiagnostics $diagnostics = new ConversationDiagnostics(),
	)
	{
	}

	/**
	 * Closes grouped reads before a placement mutation can make activity rows stale.
	 *
	 * The returned value must be passed to {@see finishPlacementMutation()}. A crash between the two
	 * calls leaves readiness disabled, so the plain list is used until the stepper rebuilds the projection.
	 */
	public function beginPlacementMutation(int $mailboxId): ?string
	{
		return $this->listRepository->beginProjectionMutation($mailboxId);
	}

	/**
	 * Restores readiness only after all affected activity rows were replaced successfully.
	 *
	 * @param int[] $messageIds
	 */
	public function finishPlacementMutation(
		int $mailboxId,
		array $messageIds,
		?string $claim,
		bool $refreshComplete = true,
	): void
	{
		if (!$refreshComplete)
		{
			$this->listRepository->cancelProjectionMutation($mailboxId, $claim);
			MessageConversationStepper::bind();

			return;
		}

		try
		{
			$result = $this->indexer->refreshActivitiesForMessages($messageIds, $mailboxId);
			if (
				$result->isSuccess()
				&& $claim !== null
				&& $this->listRepository->finishProjectionMutation($mailboxId, $claim)
			)
			{
				return;
			}
		}
		catch (\Throwable)
		{
		}

		$this->listRepository->cancelProjectionMutation($mailboxId, $claim);
		MessageConversationStepper::bind();
	}

	/**
	 * Projects the message whose closure has just been saved, before the list of the client is told
	 * to refresh itself.
	 *
	 * @return bool whether the projection now holds the message
	 */
	public function indexMessage(int $mailboxId, int $messageId): bool
	{
		$projection = $this->index($mailboxId, $messageId);
		if ($projection !== null && $projection['complete'])
		{
			return true;
		}

		$this->suspendReadiness($mailboxId);

		return false;
	}

	/**
	 * One portion of the pass over the mailbox: every message after the cursor is projected until the
	 * portion is spent, and the cursor of the answer is where the next portion starts.
	 *
	 * Every call projects at most one bounded piece. An unfinished merge leaves the cursor before the
	 * current message, so the next Stepper execution resumes it without storing a growing frontier.
	 *
	 * @return array{lastMessageId: int, processed: int, failed: int, exhausted: bool, merged: bool}
	 */
	public function indexPortion(int $mailboxId, int $afterMessageId, int $limit = self::PORTION_SIZE): array
	{
		$limit = max(1, min($limit, self::PORTION_SIZE));
		$messageIds = $this->repository->findMessageIdsAfter($mailboxId, $afterMessageId, $limit);
		try
		{
			$batchResult = $this->indexer->indexMany($messageIds, $mailboxId, $limit);
		}
		catch (\Throwable)
		{
			$batchResult = null;
		}
		if ($batchResult !== null && $batchResult->isSuccess())
		{
			$items = $batchResult->getData()['items'] ?? null;
			if (is_array($items))
			{
				return $this->batchPortionResult($messageIds, $items, $afterMessageId, $limit);
			}
		}

		return $this->indexPortionIndividually($mailboxId, $messageIds, $afterMessageId, $limit);
	}

	/** Clears activity once before the stepper starts or restarts a complete mailbox pass. */
	public function startActivityRebuild(int $mailboxId): void
	{
		$this->repository->resetMailboxActivities($mailboxId);
	}

	/**
	 * @param int[] $messageIds
	 * @return array{lastMessageId: int, processed: int, failed: int, exhausted: bool, merged: bool}
	 */
	private function indexPortionIndividually(
		int $mailboxId,
		array $messageIds,
		int $afterMessageId,
		int $limit,
	): array
	{
		$lastMessageId = $afterMessageId;
		$processed = 0;
		$failed = 0;
		$merged = false;
		foreach ($messageIds as $messageId)
		{
			$remainingBudget = $limit - $processed;
			if ($remainingBudget <= 0)
			{
				break;
			}

			$projection = $this->index($mailboxId, $messageId, $remainingBudget);
			if ($projection === null)
			{
				$failed++;
				$lastMessageId = $messageId;

				continue;
			}
			$processed += $projection['processed'];
			$merged = $merged || $projection['merged'];
			if (!$projection['complete'])
			{
				break;
			}

			$lastMessageId = $messageId;
			if ($processed >= $limit)
			{
				break;
			}
		}

		return [
			'lastMessageId' => $lastMessageId,
			'processed' => $processed,
			'failed' => $failed,
			'exhausted' => $lastMessageId === ($messageIds[count($messageIds) - 1] ?? $afterMessageId)
				&& count($messageIds) < $limit,
			'merged' => $merged,
		];
	}

	/**
	 * @param int[] $messageIds
	 * @param array<int, array{messageId: int, complete: bool, merged: bool, processed: int}> $items
	 * @return array{lastMessageId: int, processed: int, failed: int, exhausted: bool, merged: bool}
	 */
	private function batchPortionResult(array $messageIds, array $items, int $afterMessageId, int $limit): array
	{
		$lastMessageId = $afterMessageId;
		$processed = 0;
		$merged = false;
		foreach ($items as $projection)
		{
			$messageId = (int)($projection['messageId'] ?? 0);
			$processed += (int)($projection['processed'] ?? 0);
			$merged = $merged || (bool)($projection['merged'] ?? false);
			if (!(bool)($projection['complete'] ?? false))
			{
				break;
			}

			$lastMessageId = $messageId;
			if ($processed >= $limit)
			{
				break;
			}
		}

		return [
			'lastMessageId' => $lastMessageId,
			'processed' => $processed,
			'failed' => 0,
			'exhausted' => $lastMessageId === ($messageIds[count($messageIds) - 1] ?? $afterMessageId)
				&& count($messageIds) < $limit,
			'merged' => $merged,
		];
	}

	/**
	 * One portion of what the projection must not keep: a member whose message row is gone is dropped
	 * and what is left of its conversation is split into the components it really consists of, and a
	 * conversation without members at all is deleted.
	 *
	 * A portion where nothing could be removed reports itself as the last one: the same rows would
	 * come back forever otherwise.
	 *
	 * @return array{removed: int, failed: int, exhausted: bool}
	 */
	public function prunePortion(int $mailboxId, int $limit = self::PORTION_SIZE): array
	{
		$messageIds = $this->repository->findStaleMemberMessageIds($mailboxId, $limit);
		$removal = $this->removeMembers($mailboxId, $messageIds);
		$removed = $removal['removed'];
		$failed = $removal['failed'];

		$emptied = $this->deleteEmptyConversations($mailboxId, $limit);

		return [
			'removed' => $removed + $emptied,
			'failed' => $failed,
			'exhausted' => ($removed === 0 || count($messageIds) < $limit) && $emptied < $limit,
		];
	}

	/**
	 * What the projection of the mailbox is missing, holds too much of and cannot show completely.
	 *
	 * @return array{pending: int, stale: int, incomplete: int}
	 */
	public function reconcile(int $mailboxId): array
	{
		return [
			'pending' => $this->repository->countUnprojectedMessages($mailboxId),
			'stale' => $this->repository->countStaleMembers($mailboxId),
			'incomplete' => $this->repository->countIncompleteConversations($mailboxId),
		];
	}

	/**
	 * Ends the pass over the mailbox: the check decides, not the pass itself. Grouping is allowed only
	 * when nothing failed, nothing is missing and nothing is left over; an incomplete conversation
	 * does not stand in the way, because it is a state of the synchronization and not of the
	 * projection.
	 *
	 * @param int $failedCount messages the pass could not project
	 * @return bool whether the mailbox may group its list from now on
	 */
	public function completePass(int $mailboxId, int $failedCount): bool
	{
		$counters = $this->reconcile($mailboxId);
		$ready = $failedCount === 0 && $counters['pending'] === 0 && $counters['stale'] === 0;

		$this->listRepository->setProjectionReady($mailboxId, $ready);
		$this->diagnostics->passFinished(
			$mailboxId,
			$counters['pending'],
			$counters['stale'],
			$counters['incomplete'],
			$failedCount,
			$ready,
		);

		return $ready;
	}

	/** Whether the mailbox is already allowed to group its list. */
	public function isReady(int $mailboxId): bool
	{
		return $this->listRepository->isProjectionReady($mailboxId);
	}

	/**
	 * Active IMAP mailboxes after the cursor, in ascending order: the order the pass walks the portal
	 * in.
	 *
	 * @return int[]
	 */
	public function findMailboxIdsAfter(int $afterMailboxId, int $limit): array
	{
		$rows = MailboxTable::query()
			->setSelect(['ID'])
			->where('ID', '>', $afterMailboxId)
			->where('ACTIVE', 'Y')
			->where('SERVER_TYPE', 'imap')
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		return array_map('intval', array_column($rows, 'ID'));
	}

	/**
	 * Drops messages that have just been deleted for good. Each of them takes the check of its own
	 * conversation with it: what is left of the conversation is split into the components it really
	 * consists of.
	 *
	 * Every path that deletes the row of a message for good has to call this, and to call it after the
	 * deletion: while the row is stored it still connects the closure, and the rest of the conversation
	 * would be read back as one component. The only callers that must not are the ones dropping the
	 * whole mailbox: the deleted-mailbox collector answers for those in bounded pages.
	 *
	 * @param int[] $messageIds
	 */
	public function removeMessages(int $mailboxId, array $messageIds): void
	{
		$this->removeMembers($mailboxId, $this->membersAmong($messageIds));
	}

	/**
	 * The same for a caller that no longer knows which mailbox the messages belonged to: their rows are
	 * already gone, and the projection is what still names it.
	 *
	 * @param int[] $messageIds
	 */
	public function removeDeletedMessages(array $messageIds): void
	{
		foreach ($this->membersByMailbox($messageIds) as $mailboxId => $memberIds)
		{
			$this->removeMembers($mailboxId, $memberIds);
		}
	}

	/**
	 * @return array{conversationId: int, complete: bool, merged: bool, processed: int}|null
	 */
	private function index(int $mailboxId, int $messageId, int $participantLimit = self::PORTION_SIZE): ?array
	{
		try
		{
			$result = $this->indexer->index($messageId, $mailboxId, $participantLimit);
		}
		catch (\Throwable)
		{
			$this->diagnostics->indexerFailed($mailboxId, $messageId, self::UNEXPECTED_ERROR);

			return null;
		}

		if (!$result->isSuccess())
		{
			$this->diagnostics->indexerFailed($mailboxId, $messageId, $this->firstErrorCode($result));

			return null;
		}

		$data = $result->getData();

		return [
			'conversationId' => (int)($data['conversationId'] ?? self::MESSAGE_GONE),
			'complete' => (bool)($data['complete'] ?? true),
			'merged' => (bool)($data['merged'] ?? false),
			'processed' => (int)($data['processed'] ?? 0),
		];
	}

	/**
	 * No transaction is opened around the check and the deletion on purpose: a conversation that is
	 * already committed without members can never gain one, so the two statements cannot race.
	 */
	private function deleteEmptyConversations(int $mailboxId, int $limit): int
	{
		$deleted = 0;
		try
		{
			foreach ($this->repository->findEmptyConversationIds($mailboxId, $limit) as $conversationId)
			{
				$deleted += (int)$this->repository->deleteConversationIfEmpty($conversationId, $mailboxId);
			}
		}
		catch (\Throwable)
		{
			$this->diagnostics->indexerFailed($mailboxId, 0, self::UNEXPECTED_ERROR);
		}

		return $deleted;
	}

	/** @param int[] $messageIds messages the projection holds a member of */
	private function removeMembers(int $mailboxId, array $messageIds): array
	{
		if ($messageIds === [])
		{
			return ['removed' => 0, 'failed' => 0];
		}

		try
		{
			$result = $this->indexer->removeMany($messageIds, $mailboxId);
		}
		catch (\Throwable)
		{
			$result = null;
		}

		if ($result !== null && $result->isSuccess())
		{
			if ((bool)($result->getData()['rebuildRequired'] ?? false))
			{
				$this->suspendReadiness($mailboxId);
				MessageConversationStepper::bind();
			}

			return [
				'removed' => (int)($result->getData()['removed'] ?? count($messageIds)),
				'failed' => 0,
			];
		}

		$errorCode = $result === null ? self::UNEXPECTED_ERROR : $this->firstErrorCode($result);
		$this->diagnostics->indexerFailed($mailboxId, $messageIds[0], $errorCode);
		$this->suspendReadiness($mailboxId);
		MessageConversationStepper::bind();

		return ['removed' => 0, 'failed' => count($messageIds)];
	}

	/**
	 * The messages of the batch the projection really holds. A batch it holds none of - the letters of
	 * a mailbox the pass has never walked, above all - then costs one query instead of a transaction
	 * per letter. A projection that cannot be read at all is answered with the whole batch, so the
	 * removal itself reports what it could not do.
	 *
	 * @param int[] $messageIds
	 * @return int[]
	 */
	private function membersAmong(array $messageIds): array
	{
		$messageIds = $this->identifiersOf($messageIds);
		if ($messageIds === [])
		{
			return [];
		}

		try
		{
			return array_keys($this->repository->findMemberships($messageIds));
		}
		catch (\Throwable)
		{
			return $messageIds;
		}
	}

	/**
	 * @param int[] $messageIds
	 * @return array<int, int[]> messages the projection holds a member of, by the mailbox it names
	 */
	private function membersByMailbox(array $messageIds): array
	{
		$messageIds = $this->identifiersOf($messageIds);
		if ($messageIds === [])
		{
			return [];
		}

		try
		{
			$memberships = $this->repository->findMemberships($messageIds);
		}
		catch (\Throwable)
		{
			// Neither the mailbox nor the message can be named here: the projection is what would name them.
			$this->diagnostics->indexerFailed(0, 0, self::UNEXPECTED_ERROR);

			return [];
		}

		$grouped = [];
		foreach ($memberships as $messageId => $membership)
		{
			$grouped[$membership['mailboxId']][] = $messageId;
		}

		return $grouped;
	}

	/**
	 * @param int[] $messageIds
	 * @return int[]
	 */
	private function identifiersOf(array $messageIds): array
	{
		$messageIds = array_map('intval', $messageIds);

		return array_values(array_unique(array_filter(
			$messageIds,
			static fn(int $messageId): bool => $messageId > 0,
		)));
	}

	private function suspendReadiness(int $mailboxId): void
	{
		try
		{
			$this->listRepository->setProjectionReady($mailboxId, false);
		}
		catch (\Throwable)
		{
			// The projection is already behind; a failed write of the mark adds nothing to that.
		}
	}

	private function firstErrorCode(Result $result): string
	{
		$errors = $result->getErrors();

		return $errors === [] ? self::UNEXPECTED_ERROR : (string)$errors[0]->getCode();
	}
}
