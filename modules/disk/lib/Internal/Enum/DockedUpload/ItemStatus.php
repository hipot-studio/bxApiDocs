<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Enum\DockedUpload;

/**
 * Per-item state of a docked upload element.
 *
 * The wire-value (::value) is persisted to b_disk_docked_upload_item.STATUS
 * and travels in DTOs. Do not mix item state with queue state (QueueStatus)
 * or with the conflict resolution policy (ConflictPolicy).
 */
enum ItemStatus: string
{
	case Pending = 'pending';
	case Uploading = 'uploading';
	case UploadedToTemp = 'uploaded_to_temp';
	case Committed = 'committed';
	case Failed = 'failed';
	case InterruptedNeedsFile = 'interrupted_needs_file';
	case DuplicateConflict = 'duplicate_conflict';
	case Canceled = 'canceled';

	/**
	 * Whether the element counts as a transfer in flight: the bytes are either moving
	 * (uploading) or already in temp and awaiting the commit (uploaded_to_temp).
	 *
	 * This is THE membership the queue-level hasActiveTransfer flag is built from
	 * ({@see \Bitrix\Disk\Internal\Dto\DockedUpload\QueueDto::fromEntities()}), and that
	 * flag decides how the whole queue reads: whether it is displayed as waiting on the
	 * user or as transferring
	 * ({@see \Bitrix\Disk\Internal\Service\DockedUpload\DisplayStatusResolver::resolveQueueDisplayStatus()})
	 * and whether it offers Cancel all - so widening it here silently changes the header of
	 * a queue that is standing still. Do not extend it for a local need.
	 */
	public function isActiveTransfer(): bool
	{
		return match ($this)
		{
			self::Uploading, self::UploadedToTemp => true,
			default => false,
		};
	}

	/**
	 * The {@see self::isActiveTransfer()} membership as a list, for the callers that
	 * filter rows by it (SQL/ORM) instead of testing a single value.
	 *
	 * @return self[]
	 */
	public static function activeTransferStatuses(): array
	{
		return array_values(array_filter(
			self::cases(),
			static fn(self $status): bool => $status->isActiveTransfer(),
		));
	}

	/**
	 * Whether a durable artifact found by this row's INTENT_TOKEN may finalize it (ALG-01).
	 *
	 * THE status half of the crash-window reconciliation, and the only place the membership
	 * is written down: both paths that run it read it here - the restore
	 * ({@see \Bitrix\Disk\Public\Provider\DockedUpload\QueueRestoreProvider}) and the cleanup
	 * agent ({@see \Bitrix\Disk\Infrastructure\Agent\DockedUpload\QueueCleanupAgent}) - so one
	 * mechanism cannot mean two things depending on which of them reached the row first.
	 * It answers about the STATUS alone: the intent-token gate, the temp-validity check and
	 * the queue filter stay with each path (the agent walks expired queues and needs no temp
	 * gate at all).
	 *
	 * The composition, case by case:
	 * - pending / uploading / uploaded_to_temp: the transport states a commit can die in.
	 *   uploaded_to_temp is the window itself - the durable object is written before
	 *   {@see \Bitrix\Disk\Internal\Service\DockedUpload\FileCommitService} marks the row -
	 *   while pending and uploading are reachable with the token of an earlier attempt after
	 *   a retry, where finalizing beats uploading a file that is already on the Disk a second
	 *   time.
	 * - failed: the same crash seen from a live tab, where the client escalates the commit
	 *   whose answer never arrived, so the row reads as an error over an object that exists.
	 * - interrupted_needs_file: the temp is gone, and without this the user would be asked to
	 *   re-select a file that has already landed.
	 * - committed is out (finalized already, the call would be a no-op) and canceled is the
	 *   user terminal, whose durable leftover is torn down by
	 *   {@see \Bitrix\Disk\Internal\Service\DockedUpload\DurableOrphanReleaser}, never committed.
	 * - duplicate_conflict is out because it has no commit in flight to finish. The only way
	 *   from it to a commit is Replace, and that moves the row to uploaded_to_temp with a
	 *   guarded write BEFORE it commits
	 *   ({@see \Bitrix\Disk\Public\Command\DockedUpload\ResolveConflictCommand}), so a row
	 *   still standing in the conflict is one waiting for the user. It cannot carry a durable
	 *   artifact of an earlier attempt either: a commit reconciles by token before it looks
	 *   for a name collision
	 *   ({@see \Bitrix\Disk\Internal\Service\DockedUpload\FileCommitService::commit()}), so
	 *   the row that would have been marked as a conflict goes to committed instead.
	 */
	public function isDurableReconcilable(): bool
	{
		return match ($this)
		{
			self::Pending,
			self::Uploading,
			self::UploadedToTemp,
			self::Failed,
			self::InterruptedNeedsFile => true,
			default => false,
		};
	}
}
