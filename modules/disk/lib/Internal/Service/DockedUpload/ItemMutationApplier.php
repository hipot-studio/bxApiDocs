<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

use Bitrix\Disk\Internal\Dto\DockedUpload\ItemDto;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItem;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;
use Bitrix\Disk\Internal\Enum\DockedUpload\QueueStatus;
use Bitrix\Disk\Internal\Repository\DockedUpload\DockedUploadItemRepository;
use Bitrix\Disk\Internal\Repository\Interface\DockedUploadQueueRepositoryInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;

/**
 * Applies one item mutation - the per-row half of API-01 updateItem: loads and
 * isolates the row, gates the requested status change against the transport
 * transition matrix, routes the transport-failure / release / ownership-CAS /
 * guarded-transition branches, triggers the synchronous commit on the happy
 * path and re-reads the authoritative state into an ItemDto.
 *
 * It is an Internal service because both UpdateItemCommand (single row) and
 * UpdateItemsCommand (batch, CONTRACT-API-06) run exactly this logic per row,
 * and a command may never be called from another command. The commit path
 * (ALG-01) stays three independent durable units - it is never wrapped in a
 * transaction here, and the batch caller adds no enclosing transaction either.
 *
 * Exceptions are deliberately NOT caught here: each command owns its policy
 * (the single command fails whole, the batch turns them into per-row errors).
 */
final class ItemMutationApplier
{
	public function __construct(
		private readonly DockedUploadItemRepository $itemRepository,
		private readonly DockedUploadQueueRepositoryInterface $queueRepository,
		private readonly DisplayStatusResolver $resolver,
		private readonly ?FileCommitService $fileCommitService = null,
		private readonly ?OwnershipService $ownershipService = null,
		/**
		 * Whether the bytes behind each token the hand-back rows of a BATCH report are in
		 * temp, resolved once for the whole packet by the caller
		 * ({@see \Bitrix\Disk\Public\Command\DockedUpload\UpdateItemsCommand}). Empty on
		 * the single-row surface, where one row means one question anyway.
		 *
		 * @var array<string, bool>
		 */
		private readonly array $reportedTempPresence = [],
	)
	{
	}

	public function apply(int $userId, ItemMutation $mutation): ItemMutationOutcome
	{
		$item = $this->itemRepository->getById($mutation->itemId);
		if ($item === null)
		{
			return ItemMutationOutcome::rejected(new Error('Item not found', 'DISK_DOCKED_ITEM_NOT_FOUND'));
		}

		$queue = $this->queueRepository->getById((int)$item->getQueueId());
		if ($queue === null || (int)$queue->getUserId() !== $userId)
		{
			// Isolation: a foreign item is invisible.
			return ItemMutationOutcome::rejected(new Error('Item not found', 'DISK_DOCKED_ITEM_NOT_FOUND'));
		}

		// API-01 addresses file elements only. A structural folder-item has no transport of
		// its own - the client never mutates one, it holds no local File - and every branch
		// below is undefined for it: a commit would create a plain file named after the
		// folder (and leave createFolderTree reading the terminal row as a called-off
		// upload, so the sub-tree lands flat in the root), a transport failure would break
		// the invariant that a folder-item is never failed, a release would reclassify it,
		// and even the answer is built by fromFileItem() and would drop its aggregate
		// fields. Refused before any of that, and before the DTO is built.
		// The cascade paths that legitimately touch the structural row - cancel, dismiss,
		// the retry cascade - do not come through here.
		if ($item->isFolder())
		{
			return ItemMutationOutcome::rejected(new Error('Item is not a file element', 'DISK_DOCKED_ITEM_NOT_FILE'));
		}

		// Gate the client-requested status change against the transport transition
		// matrix BEFORE any write: the client may never set a server-owned state
		// (committed/failed/...) nor rewind a terminal row back into an active
		// transport state. Without this a stale INTENT_TOKEN replayed against an
		// already-committed item could re-open the exactly-once side-effect gate.
		$statusError = $this->guardStatusTransition($mutation->status, $item->getStatus());
		if ($statusError !== null)
		{
			return ItemMutationOutcome::rejected($statusError);
		}

		$stateChanged = $this->applyBranches($userId, $mutation, $item, $queue->getStatus() ?? QueueStatus::Active);

		// Re-read to expose the authoritative row: a commit produced a terminal
		// status, and a lost guarded transition (affectedRows === 0) left the row in
		// whatever state the winning writer set - either way the client must see the
		// actual state, never the pre-transition snapshot.
		$item = $this->itemRepository->getById($mutation->itemId) ?? $item;

		// Re-read the queue so the pull payload carries a quota stop the commit
		// may have just applied (queue -> stopped_by_quota).
		$queue = $this->queueRepository->getById((int)$item->getQueueId()) ?? $queue;
		$queueStatus = $queue->getStatus() ?? QueueStatus::Active;
		$itemDto = ItemDto::fromFileItem($item, $queueStatus, $this->resolver)->toArray();

		return ItemMutationOutcome::applied($itemDto, $item, $queue, $stateChanged);
	}

	private function ownership(): OwnershipService
	{
		return $this->ownershipService
			?? ServiceLocator::getInstance()->get('disk.dockedUpload.ownershipService');
	}

	private function commitService(): FileCommitService
	{
		return $this->fileCommitService
			?? ServiceLocator::getInstance()->get('disk.dockedUpload.fileCommitService');
	}

	/**
	 * Transport-status transition guard (API-01, exactly-once).
	 *
	 * The client drives ONLY the transport states (pending / uploading / uploaded_to_temp /
	 * interrupted_needs_file). Server-owned terminal/derived states
	 * (committed / failed / duplicate_conflict / canceled) are produced by
	 * {@see FileCommitService} or by dedicated commands (Cancel/Retry/ResolveConflict),
	 * never by a raw updateItem. A terminal row (committed / canceled) is immutable:
	 * rewinding it back into an active transport state would let a replayed stale
	 * INTENT_TOKEN re-open the exactly-once side-effect gate (re-run BizProc/analytics
	 * without a fresh temp file).
	 *
	 * @return Error|null a domain error when the requested status change is
	 *   rejected, or null when there is no status change (heartbeat / tempToken-only)
	 *   or it is a legal transport transition.
	 */
	private function guardStatusTransition(?ItemStatus $requested, ?ItemStatus $current): ?Error
	{
		if ($requested === null)
		{
			// Ownership/heartbeat or tempToken-only updateItem: no status change,
			// leave this path exactly as before.
			return null;
		}

		// A terminal row is immutable - unconditionally block leaving committed/canceled.
		if ($current === ItemStatus::Committed || $current === ItemStatus::Canceled)
		{
			return new Error('Item is in a terminal state', 'DISK_DOCKED_ITEM_TERMINAL');
		}

		// Server-owned states are computed by the server, never set by the client.
		$serverOwned = [
			ItemStatus::Committed,
			ItemStatus::Canceled,
			ItemStatus::Failed,
			ItemStatus::DuplicateConflict,
		];
		if (in_array($requested, $serverOwned, true))
		{
			return new Error('Status is not client-settable', 'DISK_DOCKED_ITEM_STATUS_FORBIDDEN');
		}

		// Only the transport transitions the client actually performs are allowed.
		if (!in_array($requested, $this->allowedTransportTargets($current), true))
		{
			return new Error('Illegal item status transition', 'DISK_DOCKED_ITEM_TRANSITION_INVALID');
		}

		return null;
	}

	/**
	 * Allowed client transport targets keyed by the item's current status. Any
	 * state not listed here (failed / duplicate_conflict / terminal / unknown) has
	 * no client-driven transition - those are owned by dedicated commands.
	 *
	 * @return ItemStatus[]
	 */
	private function allowedTransportTargets(?ItemStatus $current): array
	{
		return match ($current)
		{
			ItemStatus::Pending => [
				ItemStatus::Pending,
				ItemStatus::Uploading,
				ItemStatus::UploadedToTemp,
				ItemStatus::InterruptedNeedsFile,
			],
			ItemStatus::Uploading => [
				ItemStatus::Uploading,
				ItemStatus::UploadedToTemp,
				ItemStatus::InterruptedNeedsFile,
			],
			ItemStatus::UploadedToTemp => [
				ItemStatus::UploadedToTemp,
				ItemStatus::InterruptedNeedsFile,
			],
			// interrupted_needs_file -> pending is the reverse of the release that put the
			// element here: the client has handed the missing File back and the element is
			// queued again, waiting its turn in that tab's transport. It is what an
			// element re-selected after a reload needs and cannot express otherwise - marking
			// it uploading straight away would tell the queue that a folder of hundreds of
			// files is transferring all of them at once, and leaving it interrupted would keep
			// its folder blocked on a file that is no longer missing. Nothing is committed and
			// nothing is undone by it: pending is the state the element was created in.
			ItemStatus::InterruptedNeedsFile => [
				ItemStatus::Pending,
				ItemStatus::Uploading,
				ItemStatus::UploadedToTemp,
				ItemStatus::InterruptedNeedsFile,
			],
			default => [],
		};
	}

	/**
	 * @return bool whether a durable state change was produced that the other tabs
	 *   must be told about: a commit, a transport-reported terminal failure, a released
	 *   ownership, or a transition that flipped the queue-level transfer flag
	 *   ({@see self::togglesQueueTransfer()})
	 */
	private function applyBranches(
		int $userId,
		ItemMutation $mutation,
		DockedUploadItem $item,
		QueueStatus $queueStatus,
	): bool
	{
		// A terminal transport failure ends the item here: there is nothing left to
		// transition or commit. The server re-verifies the reported reason itself and
		// lands the item on its own verdict alone (the guarded terminal update inside
		// also refuses to touch an already committed/canceled row). The reported code is
		// passed on for the wording of the leftover case alone - see applyTransportFailure().
		if ($mutation->transportError !== null)
		{
			$this->commitService()->applyTransportFailure(
				$item,
				$userId,
				self::reportedTransportCode($mutation->transportError),
			);

			return true;
		}

		$hasTempToken = $mutation->tempToken !== null && $mutation->tempToken !== '';

		if ($mutation->releaseOwnership)
		{
			// Graceful hand-back (tab close): the item is reclassified and unowned so a
			// neighbouring tab can pick it up, which ends this request - there is
			// nothing left to transition or commit for a released item.
			//
			// A token reported with the hand-back travels INTO the release rather than
			// being written here: the bytes it names are what the reclassification is
			// decided by, and release() writes it under the very checks that arbitrate the
			// hand-back - the user gate, the releasable status and, when this request named
			// an ownerClientId, that it is still the owner - so it never lands on a row
			// this hand-back was refused for. A batch has already asked about every token it
			// carries in one probe, and the answer for this one rides along.
			return $this->ownership()->release(
				(int)$item->getId(),
				$userId,
				$mutation->ownerClientId,
				$hasTempToken ? $mutation->tempToken : null,
				$hasTempToken ? ($this->reportedTempPresence[$mutation->tempToken] ?? null) : null,
			) !== null;
		}

		if ($mutation->ownerClientId !== null)
		{
			// Route the ownership transition through the CAS arbiter (P4.T1): the
			// caller either still owns the row (heartbeat refresh), captures a
			// currently-unowned row, or takes it over from the owner it named as dead
			// (acquire). If none lands, another tab holds the row - reject item-level
			// and do NOT overwrite the owner. Note the CAS arbitrates ownership, not
			// liveness: a named knownStaleClientId is trusted, so an owner that is in
			// fact alive loses its row here (A7).
			$itemId = (int)$item->getId();
			$ownership = $this->ownership();
			$captured =
				$ownership->heartbeat($itemId, $userId, $mutation->ownerClientId)
				|| $ownership->acquire($itemId, $userId, $mutation->ownerClientId, $mutation->knownStaleClientId)
			;
			if (!$captured)
			{
				// The re-read in apply() exposes the actual (unchanged) state.
				return false;
			}
		}

		if ($mutation->status !== null)
		{
			$statusBeforeTransition = $item->getStatus();

			// Atomic guarded transport transition (TOCTOU-safe): flip STATUS (and the
			// temp token when supplied) only from a legal current status, so a parallel
			// commit/cancel is never clobbered by a stale full-row save. false means the
			// row does NOT stand in the requested state - it moved under us - so do NOT
			// commit, do NOT write; the re-read in apply() returns the actual state.
			// A repeat of a transition the row already made is NOT a loss: the matrix
			// allows every transport self-transition, and the repository tells a no-op
			// UPDATE from a rejected guard itself
			// ({@see DockedUploadItemRepository::transitionStatus()}).
			//
			// An element STARTING to move its bytes is held back while the queue is stopped or
			// closed, and the queue is asked in the same guarded statement: the neighbours of a
			// pre-check culprit announce their start in the same volley, and a row moved to
			// uploading behind the stop has no transport left and nothing to fix, so neither
			// a restore nor the client could tell it from a live transfer (QA 257959). It stays
			// pending - the tail the stopped queue holds. An element arriving with its bytes
			// already in temp is not held: those bytes are what a restore fixes.
			$moved = $this->itemRepository->transitionStatus(
				(int)$item->getId(),
				$userId,
				$mutation->status,
				$this->allowedSourceStatuses($mutation->status),
				$hasTempToken ? $mutation->tempToken : null,
				$mutation->status === ItemStatus::Uploading && !($statusBeforeTransition?->isActiveTransfer() ?? false),
			);
			if (!$moved)
			{
				return false;
			}

			$item->setStatus($mutation->status);
			if ($hasTempToken)
			{
				$item->setTempToken($mutation->tempToken);
			}

			$startedTransfer = $this->startTransfer(
				$userId,
				$item,
				$statusBeforeTransition,
				$mutation->status,
				$queueStatus,
			);
			if ($startedTransfer !== null && !$startedTransfer->acceptsCommit())
			{
				// The pre-check stopped the queue just now: this element is its culprit and
				// carries a terminal failure, the tail is held by the queue - and the other
				// tabs must be told, which the transfer-flag probe below would not do.
				return true;
			}

			$queueStatus = $startedTransfer ?? $queueStatus;

			// The queue must not be stopped or closed. Two states it keeps out: one stopped
			// by quota (AC-041), and a dismissed one. The close does not wait for the
			// transfers to end - it has no "no active transfer" gate of its own, the
			// confirmation belongs to the client (AC-080/087, {@see DismissCommand}) - so
			// bytes still in flight do land after it, and without this gate their object,
			// version and workflow would appear in a queue the user has closed and cannot
			// bring back (AC-077).
			// The gate is acceptsCommit(), NOT acceptsWork(): a queue still pending has not
			// started of its own accord, but bytes that did reach temp are fixed all the
			// same - dropping them would cost the user the transfer for a reason of the
			// client's making ({@see QueueStatus::acceptsCommit()}).
			$isCommitTrigger =
				$mutation->status === ItemStatus::UploadedToTemp
				&& $item->getTempToken() !== null
				&& $item->getTempToken() !== ''
				&& $queueStatus->acceptsCommit()
			;
			if ($isCommitTrigger)
			{
				$this->commitService()->commit($item, $mutation->bizProcParameters, $mutation->bizProcParametersAreSigned);

				return true;
			}

			return $this->togglesQueueTransfer($userId, $item, $statusBeforeTransition, $mutation->status);
		}

		// No status change. A tempToken-only update is a point UPDATE gated against
		// terminal statuses - never a full-row save. The ownership-only path
		// (heartbeat/acquire) already wrote its columns via the CAS in
		// OwnershipService; a full-row save() here would rewrite STATUS/DISK_OBJECT_ID
		// from the pre-CAS snapshot and could roll back a concurrently-committed item.
		if ($hasTempToken)
		{
			$this->itemRepository->updateTempToken((int)$item->getId(), $userId, $mutation->tempToken);
		}

		return false;
	}

	/**
	 * The transport code out of the reported envelope, normalised the way the controller
	 * leaves it: the client is required to send a non-empty string (API-01), and anything
	 * else - a missing key, a nested array - is read as "no code reported".
	 *
	 * @param array<string, mixed> $transportError
	 */
	private static function reportedTransportCode(array $transportError): ?string
	{
		$code = $transportError['code'] ?? null;
		if (!is_string($code) || $code === '')
		{
			return null;
		}

		return $code;
	}

	/**
	 * The queue-level half of an element starting to move its bytes.
	 *
	 * Two things happen when an element enters a transfer, and both belong to the queue
	 * rather than the row: the space left is asked for once (the pre-check of
	 * {@see FileCommitService::applyQuotaStopBeforeTransfer()}, which stops the tail
	 * deterministically when the failing tab never reported back), and, if there is room,
	 * the first transfer promotes a pending queue to active.
	 *
	 * Only the ENTRY into an active transfer is charged for it, so a repeated uploading
	 * transition costs neither a quota question nor a write; a queue that is already
	 * stopped or closed is not asked at all - its tail is not supposed to move, and
	 * failing those elements one by one is exactly what the queue-level stop avoids.
	 *
	 * @return QueueStatus|null the status this transition gave the queue, or null when
	 *   it left the queue where it was.
	 */
	private function startTransfer(
		int $userId,
		DockedUploadItem $item,
		?ItemStatus $statusBefore,
		ItemStatus $statusAfter,
		QueueStatus $queueStatus,
	): ?QueueStatus
	{
		$entersTransfer = !($statusBefore?->isActiveTransfer() ?? false) && $statusAfter->isActiveTransfer();
		if (!$entersTransfer || !$queueStatus->acceptsCommit())
		{
			return null;
		}

		// Only a transfer that is about to move bytes is worth the question: an element
		// arriving straight in temp has its bytes already, and the commit runs the same
		// pre-check on the authoritative size a moment later.
		if ($statusAfter === ItemStatus::Uploading)
		{
			$stopped = $this->commitService()->applyQuotaStopBeforeTransfer($item, $userId);
			if ($stopped !== null)
			{
				return $stopped;
			}
		}

		// Only a pending queue has a promotion left to make, and the snapshot is enough to
		// tell: pending is written once, when the queue is created, and nothing ever moves a
		// queue back into it - so a queue that is not pending in the snapshot cannot become
		// pending under us. Every element after the first would otherwise pay for an UPDATE
		// that matches no row.
		if ($queueStatus !== QueueStatus::Pending)
		{
			return null;
		}

		return $this->queueRepository->markActiveIfPending((int)$item->getQueueId())
			? QueueStatus::Active
			: null
		;
	}

	/**
	 * Whether this transition flipped the queue-level "a transfer is running" flag - the
	 * input both the queue actions and the DISPLAYED queue status are built from
	 * ({@see DisplayStatusResolver::resolveQueueActions()} and
	 * {@see DisplayStatusResolver::resolveQueueDisplayStatus()}), so only a flip leaves the
	 * client holding a stale queue header and is worth a projection plus a pull event.
	 *
	 * Both directions count. Ignition (the first element entering an active status) ends
	 * the requires_action composition: the queue is transferring again and must stop
	 * reading as waiting on the user. Extinction (the last active element leaving WITHOUT a
	 * commit, e.g. uploading -> interrupted_needs_file) restores it and can bring cancelAll
	 * back; nothing else recomputes the header on that route, so it would stay wrong.
	 *
	 * Everything in between - an element joining a transfer already running, a repeated
	 * uploading - leaves the list byte-identical and must NOT pay for a full-queue read
	 * per element: the queue holds up to
	 * {@see \Bitrix\Disk\Controller\DockedUploader\Queue::MAX_QUEUE_ITEMS} of them. Hence
	 * the cheap existence probe over the OTHER elements, taken only when this element's own
	 * membership changed.
	 *
	 * $statusBefore is the snapshot read at the start of the request, not the value the CAS
	 * itself replaced - the guarded UPDATE cannot report it without a second round-trip. A
	 * writer that moves the row in between therefore skews it, both ways harmlessly: a
	 * false "flipped" costs one extra projection built from fresh rows, and a false "did
	 * not flip" (only reachable when a concurrent release parks the row in
	 * interrupted_needs_file while we move it into an active status without committing)
	 * postpones the refresh to the next event on that queue.
	 */
	private function togglesQueueTransfer(
		int $userId,
		DockedUploadItem $item,
		?ItemStatus $statusBefore,
		ItemStatus $statusAfter,
	): bool
	{
		$wasActive = $statusBefore?->isActiveTransfer() ?? false;
		if ($wasActive === $statusAfter->isActiveTransfer())
		{
			return false;
		}

		return !$this->itemRepository->hasActiveTransferExcept(
			(int)$item->getQueueId(),
			$userId,
			(int)$item->getId(),
		);
	}

	/**
	 * The current statuses a client transition into $to may legally start from -
	 * the atomic guard set applied by the guarded UPDATE. Derived by inverting the
	 * {@see self::allowedTransportTargets()} matrix so both stay a single source of
	 * truth (the snapshot policy gate and the atomic CAS agree).
	 *
	 * @return ItemStatus[]
	 */
	private function allowedSourceStatuses(ItemStatus $to): array
	{
		$sources = [];
		foreach (
			[
				ItemStatus::Pending,
				ItemStatus::Uploading,
				ItemStatus::UploadedToTemp,
				ItemStatus::InterruptedNeedsFile,
			] as $current
		)
		{
			if (in_array($to, $this->allowedTransportTargets($current), true))
			{
				$sources[] = $current;
			}
		}

		return $sources;
	}
}
