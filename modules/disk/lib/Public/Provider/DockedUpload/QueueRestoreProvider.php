<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Provider\DockedUpload;

use Bitrix\Disk\Internal\Dto\DockedUpload\QueueDto;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItem;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItemCollection;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;
use Bitrix\Disk\Internal\Repository\DockedUpload\DockedUploadItemRepository;
use Bitrix\Disk\Internal\Repository\Interface\DockedUploadQueueRepositoryInterface;
use Bitrix\Disk\Internal\Service\DockedUpload\CommitReconciler;
use Bitrix\Disk\Internal\Service\DockedUpload\DisplayStatusResolver;
use Bitrix\Disk\Internal\Service\DockedUpload\FileCommitService;
use Bitrix\Disk\Internal\Service\DockedUpload\OwnershipService;
use Bitrix\Disk\Internal\Service\DockedUpload\QueueTargetNameResolver;
use Bitrix\Disk\Internal\Service\DockedUpload\TempPresenceProbe;
use Bitrix\Disk\Internal\Service\Logger\LoggerWrapper;
use Psr\Log\LoggerInterface;

/**
 * Read-path provider for restoring the user's actionable queues (API-02).
 *
 * It returns every queue a restore may bring back
 * ({@see \Bitrix\Disk\Internal\Enum\DockedUpload\QueueStatus::restorableCases()}) -
 * regardless of the currently open folder - and excludes completed / dismissed queues.
 *
 * Three batched passes keep the whole restore cheap on a large tree (no N+1):
 *
 *  - Temp reconciliation (R6): the temp presence of ALL restored items is resolved
 *    by {@see TempPresenceProbe} with a handful of chunked IN(...) queries keyed on
 *    the token GUID - never a per-token {@see \Bitrix\UI\FileUploader\Uploader::getPendingFiles()}
 *    / getStatus (one DB query each). The effective transport status is computed in
 *    memory only.
 *  - Intent reconciliation (R1): before an item whose commit never answered would be
 *    shown as interrupted_needs_file or as an error, its INTENT_TOKEN is probed
 *    (batched) against the durable disk object/version. A crash between the domain
 *    write and the atomic commit leaves exactly such an artifact; for those items the
 *    provider drives the idempotent guarded finalize to committed via
 *    {@see FileCommitService::commit()} - it re-materialises nothing and fires the
 *    durable side effects exactly once.
 *  - Destination names: the distinct destinations of the whole batch are resolved by
 *    {@see QueueTargetNameResolver::prefetch()} - one pass over their folders and one
 *    over their storages - before the per-queue DTOs are built.
 *
 * The restore path carries exactly FOUR sanctioned writes: that durable-artifact
 * reconciliation, the orphan release of {@see releaseOrphanedItems()}, the conflict
 * degradation of {@see degradeConflictsWithGoneTemp()} and the drain completion of
 * {@see completeDrainedQueues()}. Every other status the restore reports is computed
 * in memory only.
 */
final class QueueRestoreProvider
{
	public function __construct(
		private readonly DockedUploadQueueRepositoryInterface $queueRepository,
		private readonly DockedUploadItemRepository $itemRepository,
		private readonly DisplayStatusResolver $resolver,
		private readonly CommitReconciler $reconciler,
		private readonly FileCommitService $commitService,
		private readonly OwnershipService $ownershipService,
		private readonly LoggerInterface $logger = new LoggerWrapper(null),
		private readonly TempPresenceProbe $tempPresenceProbe = new TempPresenceProbe(),
	)
	{
	}

	/**
	 * Whether the user has anything to restore at all (P17.T4) - the index-only question
	 * a page asks before it decides to load the uploader graph and call
	 * {@see self::getRestorableQueues()}. False means that restore would have returned an
	 * empty list, so neither the graph nor the request is worth its cost.
	 */
	public function hasRestorableQueues(int $userId): bool
	{
		return $this->queueRepository->hasActiveByUser($userId);
	}

	/**
	 * @return QueueDto[]
	 */
	public function getRestorableQueues(int $userId): array
	{
		$queues = $this->queueRepository->getActiveByUser($userId);
		if ($queues->isEmpty())
		{
			return [];
		}

		$queueIds = [];
		foreach ($queues as $queue)
		{
			$queueIds[] = (int)$queue->getId();
		}

		// One query for the items of every restored queue, grouped in memory.
		/** @var array<int, DockedUploadItemCollection> $itemsByQueue */
		$itemsByQueue = [];
		$tokens = [];
		foreach ($this->itemRepository->getByQueues($queueIds, $userId) as $item)
		{
			$queueId = (int)$item->getQueueId();
			($itemsByQueue[$queueId] ??= new DockedUploadItemCollection())->add($item);

			if ($this->needsTempCheck($item) || $this->needsOrphanTempProbe($item))
			{
				$tokens[] = (string)$item->getTempToken();
			}
		}

		// R6: batch temp reconciliation - a few chunked TempFileTable queries for the
		// whole restore instead of one getPendingFiles/getStatus per token. The second
		// return value is the set of tokens the per-request ceiling left unverified.
		[$tempValidByToken, $tempUncheckedTokens] = $this->tempPresenceProbe->resolve($tokens, $userId);

		// R1: reconcile durable-confirmed objects (crash window) to committed BEFORE
		// classification, so they surface as success rather than interrupted.
		$this->reconcileDurableItems($itemsByQueue, $tempValidByToken);

		// Only AFTER the reconciliation: an item carried to committed above is no longer
		// in flight and must never be released instead.
		$this->releaseOrphanedItems($itemsByQueue, $userId, $tempValidByToken, $tempUncheckedTokens);

		// The same release for the one status it does not cover: a conflict holds no
		// transfer, so it never was an orphan to reclaim, yet a confirmed-gone temp leaves
		// it just as unable to answer the prompt the restore puts on it.
		$this->degradeConflictsWithGoneTemp($itemsByQueue, $userId, $tempValidByToken, $tempUncheckedTokens);

		// The reconciliation and the release can have finished the last leaf of a queue -
		// the degradation above never does, it lands on a non-terminal status - and so can a
		// commit whose process died between the durable transition and the queue completion:
		// the only two writes are not one transaction. Such a queue is over but still stored
		// active, and nothing else collects it before the 24h TTL.
		$drainedQueueIds = $this->completeDrainedQueues($itemsByQueue);

		$dtos = [];
		// One resolver for the whole restore, warmed in one pass over the DISTINCT
		// destinations of the batch: the per-queue resolve() below then answers from its
		// memo, so a restore of many queues costs no lookup per queue.
		$targetNameResolver = new QueueTargetNameResolver();
		$targetNameResolver->prefetch($itemsByQueue, $userId);
		foreach ($queues as $queue)
		{
			$queueId = (int)$queue->getId();
			if (isset($drainedQueueIds[$queueId]))
			{
				continue;
			}

			$items = $itemsByQueue[$queueId] ?? new DockedUploadItemCollection();
			foreach ($items as $item)
			{
				$this->reclassify($item, $tempValidByToken, $tempUncheckedTokens);
			}

			$dtos[] = QueueDto::fromEntities($queue, $items, $this->resolver, [], $targetNameResolver);
		}

		return $dtos;
	}

	/**
	 * Completes the queues whose file leaves are all in a clean terminal state (AC-077).
	 *
	 * The predicate is taken over the rows already read for this restore, so the normal
	 * case - a queue with work left in it - costs nothing; only the rare stuck queue pays
	 * one guarded UPDATE. The verdict in memory is a filter, never the decision:
	 * {@see DockedUploadQueueRepositoryInterface::markCompletedIfDrained()} re-checks the
	 * drain inside its own NOT EXISTS and refuses a queue the user meanwhile dismissed.
	 *
	 * @param array<int, DockedUploadItemCollection> $itemsByQueue
	 * @return array<int, true> the queue ids that are over and must not be handed back
	 */
	private function completeDrainedQueues(array $itemsByQueue): array
	{
		$completed = [];
		foreach ($itemsByQueue as $queueId => $items)
		{
			if (!self::isDrained($items))
			{
				continue;
			}

			if ($this->queueRepository->markCompletedIfDrained((int)$queueId))
			{
				$completed[(int)$queueId] = true;
			}
		}

		return $completed;
	}

	/**
	 * A folder-item never commits - it is a grouping row whose real state is the aggregate
	 * over its leaves - so the drain is read off the FILE items alone, the same split
	 * {@see DockedUploadQueueRepositoryInterface::markCompletedIfDrained()} makes in SQL.
	 * A queue holding no file item at all is not drained: it never carried the work.
	 */
	private static function isDrained(DockedUploadItemCollection $items): bool
	{
		$hasFileLeaf = false;
		foreach ($items as $item)
		{
			if ($item->isFolder())
			{
				continue;
			}

			$hasFileLeaf = true;
			$status = $item->getStatus();
			if ($status !== ItemStatus::Committed && $status !== ItemStatus::Canceled)
			{
				return false;
			}
		}

		return $hasFileLeaf;
	}

	/**
	 * R1: for items whose temp is gone but which carry an INTENT_TOKEN, probe the
	 * durable object/version in one batched pass and drive a guarded finalize for
	 * the (rare) ones that already have a durable artifact - a crash between the
	 * domain write and the atomic commit. commit() reconciles by token (no
	 * re-materialisation), fires the durable side effects exactly once and mutates
	 * the in-memory item to committed, so the later reclassify() leaves it as
	 * success. Items with no durable artifact are left untouched and fall through to
	 * interrupted_needs_file.
	 *
	 * @param array<int, DockedUploadItemCollection> $itemsByQueue
	 * @param array<string, bool> $tempValidByToken
	 */
	private function reconcileDurableItems(array $itemsByQueue, array $tempValidByToken): void
	{
		/** @var DockedUploadItem[] $candidates */
		$candidates = [];
		$fileTokens = [];
		$versionTokens = [];

		foreach ($itemsByQueue as $items)
		{
			foreach ($items as $item)
			{
				if (!$this->isCommitInFlight($item))
				{
					continue;
				}

				$tempToken = (string)$item->getTempToken();
				if ($tempValidByToken[$tempToken] ?? false)
				{
					// The temp is still present: normal resume, not a crash window.
					continue;
				}

				$intentToken = (string)$item->getIntentToken();
				if ($intentToken === '')
				{
					continue;
				}

				$candidates[] = $item;
				if ($this->isVersionBranch($item))
				{
					$versionTokens[] = $intentToken;
				}
				else
				{
					$fileTokens[] = $intentToken;
				}
			}
		}

		if ($candidates === [])
		{
			return;
		}

		$durableFileTokens = $this->reconciler->findExistingFileIntentTokens($fileTokens);
		$durableVersionTokens = $this->reconciler->findExistingVersionIntentTokens($versionTokens);

		foreach ($candidates as $item)
		{
			$intentToken = (string)$item->getIntentToken();
			$hasDurable = $this->isVersionBranch($item)
				? isset($durableVersionTokens[$intentToken])
				: isset($durableFileTokens[$intentToken])
			;

			if ($hasDurable)
			{
				// Guarded, exactly-once finalize; reconciliation is only allowed for
				// these durable-confirmed artifacts - it never re-opens the commit
				// gate for an item without a durable object.
				$this->commitService->commit($item);
			}
		}
	}

	private function isVersionBranch(DockedUploadItem $item): bool
	{
		$targetFileId = $item->getTargetFileId();

		return $targetFileId !== null && $targetFileId > 0;
	}

	/**
	 * Performs the release a crashed tab could not: pagehide never ran, and a tab
	 * opened after the crash never heard the dead owner on the broadcast channel, so
	 * nothing short of the 24h TTL would reclaim the row. Until then the queue reads as
	 * transferring, offers only cancelAll and the item only cancel.
	 *
	 * A candidate is still in flight, holds no bytes in temp and has no live owner.
	 * The transition - interrupted_needs_file with the owner cleared - is exactly what
	 * {@see OwnershipService::release()} already does for an orphan; only the moment
	 * of application is new. Unlike {@see reclassify()} it is PERSISTED, so a re-select
	 * (which needs OWNER_CLIENT_ID IS NULL to acquire) sees the same state the restore
	 * reported.
	 *
	 * A token the per-request ceiling left unverified is deliberately not a candidate:
	 * its temp is unknown, not gone.
	 *
	 * @param array<int, DockedUploadItemCollection> $itemsByQueue
	 * @param array<string, bool> $tempValidByToken
	 * @param array<string, true> $uncheckedTokens
	 */
	private function releaseOrphanedItems(
		array $itemsByQueue,
		int $userId,
		array $tempValidByToken,
		array $uncheckedTokens,
	): void
	{
		/** @var array<int, DockedUploadItem> $candidates */
		$candidates = [];
		foreach ($itemsByQueue as $items)
		{
			foreach ($items as $item)
			{
				if ($this->isOrphanedCandidate($item, $tempValidByToken, $uncheckedTokens))
				{
					$candidates[(int)$item->getId()] = $item;
				}
			}
		}

		if ($candidates === [])
		{
			return;
		}

		foreach ($this->ownershipService->releaseOrphaned(array_keys($candidates), $userId) as $releasedId)
		{
			$candidates[$releasedId]
				->setStatus(ItemStatus::InterruptedNeedsFile)
				->setOwnerClientId(null)
				->setOwnerHeartbeatAt(null)
			;
		}
	}

	/**
	 * @param array<string, bool> $tempValidByToken
	 * @param array<string, true> $uncheckedTokens
	 */
	private function isOrphanedCandidate(
		DockedUploadItem $item,
		array $tempValidByToken,
		array $uncheckedTokens,
	): bool
	{
		if (!$this->isOrphanReleasable($item))
		{
			return false;
		}

		return $this->hasNoTempBytes($item, $tempValidByToken, $uncheckedTokens)
			&& $this->hasStaleOwner($item)
		;
	}

	/**
	 * The statuses a stale, byteless row may be released from. uploading / uploaded_to_temp
	 * always carry an owner by construction; a pending row does so only after a crash left
	 * OWNER_CLIENT_ID set - an unowned pending row is the live queue tail waiting for
	 * transport (its null heartbeat would read as stale) and must never be released.
	 */
	private function isOrphanReleasable(DockedUploadItem $item): bool
	{
		$status = $item->getStatus();
		if ($status === ItemStatus::Pending)
		{
			return $item->getOwnerClientId() !== null;
		}

		return in_array($status, [ItemStatus::Uploading, ItemStatus::UploadedToTemp], true);
	}

	/**
	 * A pending row that still names an owner is an orphan-release candidate, so its temp
	 * presence must be resolved too. {@see self::needsTempCheck()} collects tokens for the
	 * in-flight statuses only, so without this the pending token would be absent from the
	 * validity map and a still-present temp would wrongly read as gone. An unowned pending
	 * row is never released, so its token is not probed here.
	 */
	private function needsOrphanTempProbe(DockedUploadItem $item): bool
	{
		if ($item->getStatus() !== ItemStatus::Pending || $item->getOwnerClientId() === null)
		{
			return false;
		}

		$token = $item->getTempToken();

		return $token !== null && $token !== '';
	}

	/**
	 * An empty temp token means the transfer was marked uploading but never wrote a
	 * byte (the token is only stored once the transfer completes) - the crash case.
	 *
	 * @param array<string, bool> $tempValidByToken
	 * @param array<string, true> $uncheckedTokens
	 */
	private function hasNoTempBytes(
		DockedUploadItem $item,
		array $tempValidByToken,
		array $uncheckedTokens,
	): bool
	{
		$token = (string)$item->getTempToken();
		if ($token === '')
		{
			return true;
		}

		return ($tempValidByToken[$token] ?? false) === false && !isset($uncheckedTokens[$token]);
	}

	private function hasStaleOwner(DockedUploadItem $item): bool
	{
		$heartbeat = $item->getOwnerHeartbeatAt();

		return $heartbeat === null
			|| $heartbeat->getTimestamp() < time() - OwnershipService::OWNER_STALE_THRESHOLD_SECONDS
		;
	}

	/**
	 * Persists the one verdict {@see reclassify()} used to take in memory alone: a
	 * duplicate_conflict whose temp the batch confirmed gone (AC-042 / ERR-013).
	 *
	 * The row is shown as interrupted_needs_file with the re-select on it, and the client
	 * answers that by sending status=uploading. That request is gated against the STORED
	 * status, where duplicate_conflict has no transport target at all
	 * ({@see \Bitrix\Disk\Internal\Service\DockedUpload\ItemMutationApplier}), so a purely
	 * in-memory degradation would render a button the server refuses - with no error the
	 * user ever sees. Widening the matrix instead would make duplicate_conflict a legal
	 * SOURCE for a transfer, which would let a client throw away a conflict whose temp is
	 * still there, and the promise of a Replace with no re-transfer along with it.
	 *
	 * Owner and ERROR_CODE are cleared with the status ({@see
	 * DockedUploadItemRepository::markInterruptedFromConflict()}): the re-select acquires the
	 * row, and the conflict code no longer describes it.
	 *
	 * A token the per-request ceiling left unverified is deliberately not a candidate, the
	 * same way it is not one for the release: its temp is unknown, not gone - and here the
	 * cost of guessing is a decision the user may still take.
	 *
	 * @param array<int, DockedUploadItemCollection> $itemsByQueue
	 * @param array<string, bool> $tempValidByToken
	 * @param array<string, true> $uncheckedTokens
	 */
	private function degradeConflictsWithGoneTemp(
		array $itemsByQueue,
		int $userId,
		array $tempValidByToken,
		array $uncheckedTokens,
	): void
	{
		/** @var array<int, DockedUploadItem> $candidates */
		$candidates = [];
		foreach ($itemsByQueue as $items)
		{
			foreach ($items as $item)
			{
				if ($item->getStatus() !== ItemStatus::DuplicateConflict)
				{
					continue;
				}

				if ($this->needsTempCheck($item)
					&& $this->hasNoTempBytes($item, $tempValidByToken, $uncheckedTokens))
				{
					$candidates[(int)$item->getId()] = $item;
				}
			}
		}

		if ($candidates === [])
		{
			return;
		}

		// Only the rows that actually moved are degraded in memory too: a Replace that
		// resolved one of them first keeps it, and reporting that row as interrupted would
		// paint the restore's verdict over a decision the user has already taken.
		foreach ($this->itemRepository->markInterruptedFromConflict(array_keys($candidates), $userId) as $degradedId)
		{
			$this->degradeToInterrupted($candidates[$degradedId]);
			$candidates[$degradedId]
				->setErrorCode(null)
				->setOwnerClientId(null)
				->setOwnerHeartbeatAt(null)
			;
		}
	}

	/**
	 * The statuses whose verdict depends on whether the bytes are still in temp: the two
	 * in-flight ones and the conflict awaiting a decision (AC-042). All three land in the
	 * SAME chunked batch - the new status adds rows to the existing IN(...) query rather
	 * than a lookup path of its own, so the request count stays ceil(N / LOOKUP_CHUNK).
	 */
	private function needsTempCheck(DockedUploadItem $item): bool
	{
		$token = $item->getTempToken();
		if ($token === null || $token === '')
		{
			return false;
		}

		return in_array(
			$item->getStatus(),
			[ItemStatus::Uploading, ItemStatus::UploadedToTemp, ItemStatus::DuplicateConflict],
			true,
		);
	}

	/**
	 * Whether a commit of this element may still be finished off a durable artifact - the
	 * membership {@see reconcileDurableItems()} works over, and the same one the cleanup
	 * agent uses ({@see ItemStatus::isDurableReconcilable()}, which owns it and explains
	 * its composition, the exclusion of duplicate_conflict included).
	 *
	 * The STATUS is all this asks about. It deliberately no longer rides on
	 * {@see needsTempCheck()}: that predicate answers a different question - whose verdict
	 * depends on the bytes still being in temp - and its narrower set left the crash a live
	 * tab escalates (a `failed` row over an object that does exist) to the agent alone,
	 * hours later. The two gates this path does keep are in the loop itself: a temp still
	 * present means a normal resume rather than a crash window, and only a row carrying an
	 * INTENT_TOKEN is probed at all.
	 */
	private function isCommitInFlight(DockedUploadItem $item): bool
	{
		return $item->getStatus()?->isDurableReconcilable() === true;
	}

	/**
	 * In-memory only: a still-present temp file is exposed as uploaded_to_temp
	 * (may reach commit once the client re-attaches its File), a gone temp file as
	 * interrupted_needs_file. Never persisted (read-path). An item already reconciled
	 * to committed by {@see reconcileDurableItems()} no longer needs a temp check and
	 * is left as success.
	 *
	 * On a quota-stopped queue the surviving temp reaches no commit either - the gate is
	 * closed for the whole queue - and {@see DisplayStatusResolver} renders exactly that
	 * (blocked, AC-041). The transport status kept here stays the honest one: the bytes
	 * ARE in temp, and nothing about the queue's lifecycle belongs on the item row.
	 *
	 * A token the ceiling left unverified ({@see TempPresenceProbe::resolve()}) is unknown, not
	 * gone: it is flagged restoreTempUnchecked so the client knows the verdict is
	 * provisional and a later restore re-services it. An in-flight row still degrades on
	 * it (that has always been the safe read - the prompt costs a re-select), a conflict
	 * does not: degrading it would throw away a decision the user may still take.
	 *
	 * A duplicate_conflict keeps its prompt while the bytes it would commit are in temp -
	 * Replace commits exactly those, with no local file. The opposite verdict is NOT taken
	 * here: a confirmed-gone temp degrades the conflict to interrupted_needs_file
	 * persistently ({@see degradeConflictsWithGoneTemp()}), which mutates this entity too,
	 * so a conflict that still reads as one at this point keeps its prompt. That is not a
	 * loss of the conflict but a re-run of it: the user picks the file again and every
	 * check, the name collision included, is taken anew (AC-042, ERR-013).
	 *
	 * @param array<string, bool> $tempValidByToken
	 * @param array<string, true> $uncheckedTokens tokens skipped by the per-request ceiling
	 */
	private function reclassify(DockedUploadItem $item, array $tempValidByToken, array $uncheckedTokens): void
	{
		if (!$this->needsTempCheck($item))
		{
			return;
		}

		$token = (string)$item->getTempToken();
		if (isset($uncheckedTokens[$token]))
		{
			$item->setRestoreTempUnchecked(true);
		}

		if ($item->getStatus() === ItemStatus::DuplicateConflict)
		{
			// The degradation pass has already had its say on this row, in the DB and on
			// this entity; a conflict left standing here is one that keeps its prompt.
			return;
		}

		$hasTemp = $tempValidByToken[$token] ?? false;

		if ($hasTemp)
		{
			$item->setStatus(ItemStatus::UploadedToTemp);

			return;
		}

		$this->degradeToInterrupted($item);
	}

	/**
	 * AC-060: a restore turning a gone temp into interrupted_needs_file is a transition,
	 * so it is logged here - on the backend, not on the client (the other producer is
	 * {@see OwnershipService::release()}). Metadata only: no file names or other PII.
	 */
	private function degradeToInterrupted(DockedUploadItem $item): void
	{
		$this->logger->info('docked item interrupted', [
			'itemId' => (int)$item->getId(),
			'queueId' => (int)$item->getQueueId(),
			'fromStatus' => $item->getStatus()?->value,
		]);

		$item->setStatus(ItemStatus::InterruptedNeedsFile);
	}
}
