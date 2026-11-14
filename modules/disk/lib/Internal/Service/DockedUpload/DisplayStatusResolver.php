<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

use Bitrix\Disk\Integration\Bitrix24Manager;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;
use Bitrix\Disk\Internal\Enum\DockedUpload\QueueStatus;

/**
 * Single backend authority for the docked uploader display layer.
 *
 * It composes the two independent state dimensions (ItemStatus x QueueStatus)
 * and the failed-item ERROR_CODE variant into the display status and the list
 * of available actions the frontend renders verbatim (DTO-01). Keeping this in
 * one pure place removes the drift between the PHP enum, the TS mirror and the
 * ADR matrix.
 *
 * The methods are pure (enums/strings in, strings out) so the matrix can be unit
 * tested exhaustively; the two info-helper resolvers are the only ones that read
 * anything outside the class - {@see QuotaScenario} for the cloud quota code and
 * {@see Bitrix24Manager} for the common-storage one - and both lookups are literals
 * without side effects.
 */
class DisplayStatusResolver
{
	// ERROR_CODE values the display layer understands. They are the source of
	// truth for the failed-item variants and are reused by the size pre-check.
	public const ERROR_QUOTA_CLOUD = QuotaScenario::SCENARIO_CLOUD;
	public const ERROR_QUOTA_BOX = QuotaScenario::SCENARIO_BOX;
	public const ERROR_PERMISSION = 'permission_fail';
	public const ERROR_FILE_SIZE = 'docked_file_size';
	// A file with no bytes: the commit refuses it, so the same file never uploads and a
	// retry has nothing to offer.
	public const ERROR_FILE_EMPTY = 'docked_file_empty';
	public const ERROR_COMMON_STORAGE_BLOCKED = 'common_storage_blocked';
	public const ERROR_RESTRICTION = 'restriction';
	// A file whose folder branch could not be created (createFolderTree, API-04):
	// a name collision or a rights denial along its directory path. Non-retriable -
	// re-running the same branch hits the same block.
	public const ERROR_FOLDER_TREE_FAILED = 'docked_folder_tree_failed';
	// The transfer ended because the link did, and the wait for it to come back ran out
	// (AC-074 escalation). Retriable like any other transport failure - the difference is
	// only what the user is told, and telling them "no connection" is what keeps them from
	// hunting for a fault in the file. Landed by
	// {@see FileCommitService::applyTransportFailure()} off the code the client reports.
	public const ERROR_CONNECTION = 'connection_lost';
	// A required BizProc autostart parameter was not provided before the commit
	// (B4 / AC-049/AC-051). Retriable (SC-012 rework #2): the user clears it by
	// collecting the parameter set, and the byte-less retry re-commits carrying the
	// collected parameters (API-01 updateItem) - the temp bytes are still alive - so
	// unlike quota/permission/size a retry does have a path out.
	public const ERROR_BIZPROC_PARAMS_REQUIRED = 'bizproc_params_required';

	// Display statuses (PRD section 3, extended with the failed-item variants).
	public const DISPLAY_QUEUED = 'queued';
	public const DISPLAY_UPLOADING = 'uploading';
	public const DISPLAY_SUCCESS = 'success';
	public const DISPLAY_CANCELLED = 'cancelled';
	public const DISPLAY_INTERRUPTED = 'interrupted_needs_file';
	public const DISPLAY_DUPLICATE_CONFLICT = 'duplicate_conflict';
	public const DISPLAY_BLOCKED = 'blocked';
	public const DISPLAY_ERROR = 'error';
	public const DISPLAY_ERROR_QUOTA_CLOUD = 'error_quota_cloud';
	public const DISPLAY_ERROR_QUOTA_BOX = 'error_quota_box';
	public const DISPLAY_ERROR_PERMISSION = 'error_permission';
	public const DISPLAY_ERROR_SIZE = 'error_size';
	public const DISPLAY_ERROR_EMPTY = 'error_empty';
	public const DISPLAY_ERROR_BLOCKED = 'error_blocked';
	public const DISPLAY_ERROR_RESTRICTION = 'error_restriction';
	public const DISPLAY_ERROR_BIZPROC_PARAMS = 'error_bizproc_params';
	public const DISPLAY_ERROR_CONNECTION = 'error_connection';

	// Item action tokens.
	public const ACTION_RETRY = 'retry';
	public const ACTION_CANCEL = 'cancel';
	public const ACTION_REPLACE = 'replace';
	public const ACTION_SELECT_FILE = 'selectFile';

	// Queue action tokens. There is no dismiss among them: closing the widget is
	// addressed to the widget as a whole (SC-015), not chosen per queue - see
	// {@see self::resolveQueueActions()}.
	public const ACTION_CANCEL_ALL = 'cancelAll';
	public const ACTION_RETRY_FAILED = 'retryFailed';
	public const ACTION_RESUME = 'resume';

	/**
	 * The ERROR_CODE values for which a retry is pointless: quota, permission,
	 * restriction, the per-file size cap, an empty file and a blocked common storage. Anything
	 * else on a failed item is treated as a generic, retriable transport error.
	 */
	private const NON_RETRIABLE_ERROR_CODES = [
		self::ERROR_QUOTA_CLOUD,
		self::ERROR_QUOTA_BOX,
		self::ERROR_PERMISSION,
		self::ERROR_FILE_SIZE,
		self::ERROR_FILE_EMPTY,
		self::ERROR_COMMON_STORAGE_BLOCKED,
		self::ERROR_RESTRICTION,
		self::ERROR_FOLDER_TREE_FAILED,
	];

	public function __construct(
		private readonly QuotaScenario $quotaScenario = new QuotaScenario(),
	)
	{
	}

	/**
	 * Display status of a single item, composing its status with the owning
	 * queue status and, for a failed item, its ERROR_CODE.
	 */
	public function resolveItemDisplayStatus(
		ItemStatus $status,
		QueueStatus $queueStatus,
		?string $errorCode = null,
	): string
	{
		return match ($status)
		{
			ItemStatus::Pending => $this->isStoppedInPlace($queueStatus)
				? self::DISPLAY_BLOCKED
				: self::DISPLAY_QUEUED,
			// AC-041: a stop in place closes the commit gate for the WHOLE queue
			// ({@see \Bitrix\Disk\Public\Command\DockedUpload\UpdateItemCommand}), and no
			// path reopens it but an explicit restore. So an element still in flight, temp
			// bytes included, is going nowhere and must read as stopped, not as a transfer
			// frozen at 0%. Same composition the pending branch above makes.
			ItemStatus::Uploading, ItemStatus::UploadedToTemp => $this->isStoppedInPlace($queueStatus)
				? self::DISPLAY_BLOCKED
				: self::DISPLAY_UPLOADING,
			ItemStatus::Committed => self::DISPLAY_SUCCESS,
			ItemStatus::Canceled => self::DISPLAY_CANCELLED,
			ItemStatus::InterruptedNeedsFile => self::DISPLAY_INTERRUPTED,
			ItemStatus::DuplicateConflict => self::DISPLAY_DUPLICATE_CONFLICT,
			ItemStatus::Failed => $this->resolveFailedDisplayStatus($errorCode),
		};
	}

	/**
	 * Available item actions the frontend renders as-is (the backend is the gate).
	 *
	 * @return string[]
	 */
	public function resolveItemActions(
		ItemStatus $status,
		QueueStatus $queueStatus,
		?string $errorCode = null,
	): array
	{
		return match ($status)
		{
			ItemStatus::Committed, ItemStatus::Canceled => [],
			// The Replace commits the pending bytes as a new version of the colliding file, so
			// the affordance follows the COMMIT gate the command itself enforces
			// ({@see QueueStatus::acceptsCommit()}): where that gate is shut
			// {@see \Bitrix\Disk\Public\Command\DockedUpload\ResolveConflictCommand} answers the
			// click with an idempotent no-op, and the button would advertise a silent refusal.
			// Out of a stop in place the way back is a restore of the whole queue, not a click
			// on one row. A queue still pending is NOT such a case: its start is held up by the
			// client, and bytes that did reach temp are fixed all the same.
			ItemStatus::DuplicateConflict => $queueStatus->acceptsCommit()
				? [self::ACTION_REPLACE, self::ACTION_CANCEL]
				: [self::ACTION_CANCEL],
			// Re-selecting the file restarts the transfer from the first byte - the client
			// has no "the token is still there, only the commit is missing" route - so it
			// rides the same commit gate the Replace above does: both end in a commit, and
			// where that gate is shut the prompt would cost the user the whole file again
			// only to answer "stopped".
			ItemStatus::InterruptedNeedsFile => $queueStatus->acceptsCommit()
				? [self::ACTION_SELECT_FILE, self::ACTION_CANCEL]
				: [self::ACTION_CANCEL],
			// AC-016/076/091: a stop in place refuses every retry path, so a retriable failure
			// inside such a queue is not offered one either - the same gate resolveQueueActions()
			// puts on retryFailed, and the one every branch of
			// {@see \Bitrix\Disk\Public\Command\DockedUpload\RetryCommand} enforces
			// ({@see self::acceptsRetry()}). This also covers the folder row, whose actions are
			// derived from its blocking child: its retry affordance was rendered over a cascade
			// that re-queues nothing. A closed queue is in it too: there the retry is a silent
			// no-op the row must not advertise, and restore cannot even bring a dismissed queue
			// back to show the outcome.
			ItemStatus::Failed => $this->isRetriable($errorCode) && $this->acceptsRetry($queueStatus)
				? [self::ACTION_RETRY, self::ACTION_CANCEL]
				: [self::ACTION_CANCEL],
			// pending / uploading / uploaded_to_temp - in flight, cancel only.
			default => [self::ACTION_CANCEL],
		};
	}

	/**
	 * The ITEM-level InfoHelper slider code, by the error code of the element: a common
	 * storage switched off by the tariff (ERR-016) is the only row that carries one, so
	 * every other error - the quota codes included - resolves to null. The access
	 * restriction (ERR-015) is deliberately not among them: an upgrade offer answers a
	 * block the tariff never caused, so that row keeps its wording and loses the click.
	 *
	 * The quota code deliberately does NOT appear here either: running out of space is a
	 * QUEUE-level verdict and its CTA belongs to the queue banner and the limit popup
	 * ({@see self::resolveQuotaInfoHelperCode()}, ADR failed-variant table), so duplicating it
	 * on the row would open the tariff slider from a place that does not own that decision.
	 */
	public function resolveInfoHelperCode(?string $errorCode): ?string
	{
		return match ($errorCode)
		{
			self::ERROR_COMMON_STORAGE_BLOCKED => $this->getCommonStorageInfoHelperCode(),
			default => null,
		};
	}

	/**
	 * The QUEUE-level InfoHelper slider code of a quota stop: the cloud tariff limit is the
	 * only space shortage with a commercial way out, so it is the only one with a slider
	 * behind the banner CTA (AC-030/031/034). Paired with the quota scenario by
	 * {@see \Bitrix\Disk\Internal\Dto\DockedUpload\QueueDto::buildQuotaPayload()}, which owns
	 * the "the pair travels whole" rule.
	 */
	public function resolveQuotaInfoHelperCode(): string
	{
		return $this->quotaScenario->getCloudInfoHelperCode();
	}

	/**
	 * The common-storage restriction code, read from the map that already owns the
	 * "feature -> restriction code" pairing - the classic disk interface opens the very
	 * same case by it ({@see Bitrix24Manager::filterJsAction()}) - instead of being
	 * written out here a second time. The map is a plain literal, so the resolver stays
	 * as pure as the rest of the matrix, and it needs no cloud/box branch: without the
	 * bitrix24 module the feature reads as enabled, so
	 * {@see FolderResolverService::isCommonStorageBlocked()} never raises this code on box.
	 */
	private function getCommonStorageInfoHelperCode(): ?string
	{
		return Bitrix24Manager::getFeatureRestrictionMap()['disk_common_storage'] ?? null;
	}

	/**
	 * Display status of the whole queue: the lifecycle status passed through as the
	 * display token, plus the ONE composition the queue level owns (the per-item
	 * blocked composition lives on the items themselves).
	 *
	 * requires_action reaches the client from two sources of different strength, and
	 * telling them apart is the backend's job (ADR display-layer mapping, PRD rev. 3):
	 * - STORED {@see QueueStatus::RequiresAction} - a stop in place for lack of space
	 *   without a tariff path. It gates work: the tail waits for an explicit restore
	 *   and no retry is offered.
	 * - COMPOSED here over a queue that is still pending or active, has nothing in
	 *   flight, and holds elements waiting for a decision. It gates NOTHING: one
	 *   element's conflict or failure does not stop the rest of the queue (AC-022/048),
	 *   and the actions stay those of a working queue. Pending belongs in it because of
	 *   the element rejected BEFORE its transfer started (queued -> error, AC-055/056):
	 *   the stored status does not move, but the queue is waiting on the user.
	 *
	 * The composed value is never written down - {@see \Bitrix\Disk\Internal\Service\DockedUpload\FileCommitService}
	 * is the only writer of the stored one - and it needs no exit: once a transfer
	 * resumes, the condition simply stops holding.
	 *
	 * @param bool $hasItemAwaitingDecision whether any element of the queue waits for a
	 *   user decision ({@see self::awaitsDecision()})
	 */
	public function resolveQueueDisplayStatus(
		QueueStatus $queueStatus,
		bool $hasActiveTransfer,
		bool $hasItemAwaitingDecision,
	): string
	{
		$composesRequiresAction =
			($queueStatus === QueueStatus::Pending || $queueStatus === QueueStatus::Active)
			&& !$hasActiveTransfer
			&& $hasItemAwaitingDecision
		;

		return $composesRequiresAction ? QueueStatus::RequiresAction->value : $queueStatus->value;
	}

	/**
	 * Whether an element is waiting for the user to decide what happens to it - the
	 * membership the queue-level composition of {@see self::resolveQueueDisplayStatus()}
	 * is built from, and the only place it is spelled out.
	 *
	 * A failure, a name conflict and a lost temp are the three ways an element stops
	 * moving without being finished: the queue around it may be perfectly healthy, so the
	 * only thing that changes is that the queue reads as waiting on the user.
	 */
	public function awaitsDecision(ItemStatus $status): bool
	{
		return match ($status)
		{
			ItemStatus::Failed, ItemStatus::DuplicateConflict, ItemStatus::InterruptedNeedsFile => true,
			default => false,
		};
	}

	/**
	 * Whether the queue is stopped IN PLACE - the two stored states a queue only leaves
	 * through an explicit restore (API-07) or a cancel: the cloud tariff limit
	 * ({@see QueueStatus::StoppedByQuota}) and every other space shortage
	 * ({@see QueueStatus::RequiresAction}).
	 *
	 * Reads the STORED status only. The requires_action a queue is merely DISPLAYED with
	 * ({@see self::resolveQueueDisplayStatus()}) is not a stop and must never reach this
	 * predicate - it is composed from the display inputs, not from the status.
	 */
	public function isStoppedInPlace(QueueStatus $queueStatus): bool
	{
		return match ($queueStatus)
		{
			QueueStatus::StoppedByQuota, QueueStatus::RequiresAction => true,
			default => false,
		};
	}

	/**
	 * Whether a retry may re-queue anything in a queue in this status (AC-016/076/091).
	 *
	 * THE retry gate: every branch of
	 * {@see \Bitrix\Disk\Public\Command\DockedUpload\RetryCommand} - single item, folder
	 * cascade, scope:failed and the itemIds batch - reads it, and so do the two
	 * affordances that offer the click ({@see self::resolveItemActions()} and
	 * {@see self::resolveQueueActions()}), so the button and the command cannot drift
	 * apart. The gate is the server's, not the widget's: a retry aimed at a stopped
	 * queue is refused even when the client drew the button.
	 *
	 * Refused by both stops in place (the way out of them is a restore, never a retry)
	 * and by the two closed states, which have nothing to re-queue into. Pending is NOT
	 * a stop: the queue has not started for a reason of the client's making, and an
	 * element it rejected in a pre-check is exactly what a retry answers there.
	 */
	public function acceptsRetry(QueueStatus $queueStatus): bool
	{
		return match ($queueStatus)
		{
			QueueStatus::Pending, QueueStatus::Active => true,
			default => false,
		};
	}

	/**
	 * Available queue-level actions (AC-054/AC-076/AC-094).
	 *
	 * $hasRetriableFailed means a failed item a retry would actually requeue - the same
	 * {@see self::isRetriable()} rule the item gate uses. A queue whose failures are all
	 * unrepeatable (size, quota, permission, ...) offers no retryFailed: the scope:failed
	 * batch would move nothing.
	 *
	 * Closing is NOT among the queue actions (PRD rev. 3): the confirmed close is
	 * addressed to the widget as a whole and applies to every queue at once (SC-015,
	 * {@see \Bitrix\Disk\Public\Command\DockedUpload\DismissCommand}), so the global cross
	 * is available at all times and needs no per-queue affordance. Listed here it would
	 * read as "this queue can be closed on its own" - the rev. 2 behaviour rev. 3 removed;
	 * the queue cross and Cancel all lead a queue to completed instead (AC-088/089). The
	 * client takes the need for a confirmation from the unfinished elements and the
	 * recoverability from QueueDto.restorable, neither of which is an action.
	 *
	 * @return string[]
	 */
	public function resolveQueueActions(
		QueueStatus $queueStatus,
		bool $hasActiveTransfer,
		bool $hasRetriableFailed,
		bool $hasActionable,
	): array
	{
		$actions = [];

		if ($hasActiveTransfer || $hasActionable)
		{
			$actions[] = self::ACTION_CANCEL_ALL;
		}

		// AC-054/094: restoring a queue is offered exactly where
		// {@see \Bitrix\Disk\Public\Command\DockedUpload\ResumeCommand} lets it through -
		// the two STORED stops in place ({@see self::isStoppedInPlace()}), whichever
		// mechanism ran out of space. The composed requires_action is not one of them: that
		// queue never stopped, so there is nothing to restore (same asymmetry the retry
		// gate has, AC-016/091).
		if ($this->isStoppedInPlace($queueStatus))
		{
			$actions[] = self::ACTION_RESUME;
		}

		// AC-076/091: the batch is held by the same gate as every other retry path
		// ({@see self::acceptsRetry()}) - a stopped or closed queue re-queues nothing, so
		// the offer is withdrawn instead of being answered with a silent no-op. Beside the
		// two stops in place that covers a queue the user dismissed, which no restore
		// brings back.
		if ($hasRetriableFailed && $this->acceptsRetry($queueStatus))
		{
			$actions[] = self::ACTION_RETRY_FAILED;
		}

		return $actions;
	}

	public function isRetriable(?string $errorCode): bool
	{
		if ($errorCode === null || $errorCode === '')
		{
			// A failed item with no specific code is a generic transport error.
			return true;
		}

		return !in_array($errorCode, self::NON_RETRIABLE_ERROR_CODES, true);
	}

	/**
	 * Whether the failure carries the QUEUE's stop for space rather than a fault of the
	 * element itself - the one failure a restore (API-07) re-commits, because its bytes are
	 * untouched in temp and no retry can ever move it (a quota code is non-retriable by
	 * construction, so restoring the queue is its only way out).
	 */
	public function isQuotaStopCode(?string $errorCode): bool
	{
		return $errorCode === self::ERROR_QUOTA_CLOUD || $errorCode === self::ERROR_QUOTA_BOX;
	}

	private function resolveFailedDisplayStatus(?string $errorCode): string
	{
		return match ($errorCode)
		{
			self::ERROR_QUOTA_CLOUD => self::DISPLAY_ERROR_QUOTA_CLOUD,
			self::ERROR_QUOTA_BOX => self::DISPLAY_ERROR_QUOTA_BOX,
			self::ERROR_PERMISSION => self::DISPLAY_ERROR_PERMISSION,
			self::ERROR_FILE_SIZE => self::DISPLAY_ERROR_SIZE,
			self::ERROR_FILE_EMPTY => self::DISPLAY_ERROR_EMPTY,
			self::ERROR_COMMON_STORAGE_BLOCKED => self::DISPLAY_ERROR_BLOCKED,
			self::ERROR_RESTRICTION => self::DISPLAY_ERROR_RESTRICTION,
			self::ERROR_BIZPROC_PARAMS_REQUIRED => self::DISPLAY_ERROR_BIZPROC_PARAMS,
			// A lost link is a variant of the generic error, not a family of its own: it is
			// absent from NON_RETRIABLE_ERROR_CODES, so the row keeps the retry the matrix
			// gives every repeatable failure and the queue keeps counting it among the ones
			// retryFailed would requeue.
			self::ERROR_CONNECTION => self::DISPLAY_ERROR_CONNECTION,
			default => self::DISPLAY_ERROR,
		};
	}
}
