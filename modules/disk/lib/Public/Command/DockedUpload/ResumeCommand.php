<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\DockedUpload;

use Bitrix\Disk\Internal\Dto\DockedUpload\QueueDto;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItem;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItemCollection;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;
use Bitrix\Disk\Internal\Enum\DockedUpload\QueueStatus;
use Bitrix\Disk\Internal\Repository\DockedUpload\DockedUploadItemRepository;
use Bitrix\Disk\Internal\Repository\Interface\DockedUploadQueueRepositoryInterface;
use Bitrix\Disk\Internal\Service\DockedUpload\DisplayStatusResolver;
use Bitrix\Disk\Internal\Service\DockedUpload\FileCommitService;
use Bitrix\Disk\Internal\Service\DockedUpload\QueueEventNotifier;
use Bitrix\Disk\Internal\Service\DockedUpload\QueueTargetNameResolver;
use Bitrix\Disk\Internal\Service\DockedUpload\TempPresenceProbe;
use Bitrix\Disk\Internal\Service\DockedUpload\UnexpectedErrorReporter;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

/**
 * Restores a queue stopped for lack of space (API-07, AC-054/094).
 *
 * Despite the name this is the "Restore queue" action, not a pause/resume of the
 * transfer: pausing is out of the v1 scope. The queue keeps its identity and its
 * successful results - no new queue is created (the single exception to AC-063) - and
 * goes back to active from either stored stop: the cloud tariff limit
 * ({@see QueueStatus::StoppedByQuota}) and every other space shortage
 * ({@see QueueStatus::RequiresAction}) differ in the error beside them, never in the way
 * back. The requires_action a healthy queue is merely DISPLAYED with is not a stop and is
 * refused here, exactly as it is by the retry gate (AC-016/091).
 *
 * Elements whose bytes are confirmed to be in temp are committed straight away, so
 * nothing is transferred twice. The elements the stop left with no bytes to fix and no way
 * out of their own go back to pending (QA 257959): the quota culprit of the pre-check,
 * failed with a non-retriable quota code before a byte reached temp, and an element left in
 * uploading without a temp token, whose transport is gone with the stop. The client then
 * transfers them like any other queued element; left as they were, the culprit would keep
 * the quota error with no button and the other would read as a transfer that never moves.
 * Everything else keeps its status: the pending tail is carried on by the owning tab, or -
 * after a page reload - is already classified as interrupted_needs_file by the restore
 * path (AC-042).
 *
 * Free space is deliberately NOT re-checked on the way in: if it was never freed, the
 * first commit stops the queue again, in the same state and through the same mechanism
 * ([P6.T2]), and the pass ends on that stop - nothing else is fixed into a queue that is
 * stopped once more, however small the element next in line. A pre-check would answer with
 * a refusal the user cannot tell from a dead button, while a repeated stop states the
 * actual reason. Such a pass requeues nothing either. A restore with no bytes to commit
 * meets no shortage on the server at all: the queue stays active, the requeued elements
 * start transferring, and the pre-check of the first one stops the queue again.
 */
class ResumeCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $userId,
		public readonly int $queueId,
		private readonly ?DockedUploadItemRepository $itemRepository = null,
		private readonly ?DockedUploadQueueRepositoryInterface $queueRepository = null,
		private readonly ?DisplayStatusResolver $resolver = null,
		private readonly ?FileCommitService $commitService = null,
		private readonly ?QueueEventNotifier $eventNotifier = null,
		private readonly TempPresenceProbe $tempPresenceProbe = new TempPresenceProbe(),
	)
	{
	}

	protected function execute(): Result
	{
		$result = new Result();

		$itemRepository = $this->itemRepository ?? new DockedUploadItemRepository();
		$queueRepository = $this->queueRepository
			?? ServiceLocator::getInstance()->get(DockedUploadQueueRepositoryInterface::class);
		$resolver = $this->resolver
			?? ServiceLocator::getInstance()->get('disk.dockedUpload.displayStatusResolver');

		$queue = $queueRepository->getById($this->queueId);
		if ($queue === null || (int)$queue->getUserId() !== $this->userId)
		{
			// A foreign queue is answered exactly like a missing one, and never with a
			// silent empty success: the user must not learn that it exists.
			return $result->addError(new Error('Queue not found', 'DISK_DOCKED_QUEUE_NOT_FOUND'));
		}

		if (!$resolver->isStoppedInPlace($queue->getStatus() ?? QueueStatus::Active))
		{
			return $result->addError(new Error('Queue is not resumable', 'DISK_DOCKED_QUEUE_NOT_RESUMABLE'));
		}

		// The gate and the write in one guarded statement: between the read above and
		// here a concurrent cancel may have drained the queue to completed, and a
		// full-row save would resurrect it as active against that cancellation.
		try
		{
			$restored = $queueRepository->markActiveIfStopped((int)$queue->getId());
		}
		catch (Throwable $exception)
		{
			return $result->addError(UnexpectedErrorReporter::report($exception, 'resume'));
		}

		if (!$restored)
		{
			return $result->addError(new Error('Queue is not resumable', 'DISK_DOCKED_QUEUE_NOT_RESUMABLE'));
		}

		$queue->setStatus(QueueStatus::Active);

		$items = $itemRepository->getByQueue((int)$queue->getId(), $this->userId);

		// The commit runs its own transactions (ALG-01: intent, domain write, atomic
		// commit) and is deliberately not wrapped in one here - the queue is already
		// active durably, and a commit that stops it again by quota must persist that
		// stop rather than roll back with an enclosing transaction.
		$tempValidByToken = $this->commitConfirmedItems($items, $resolver, $queueRepository);
		$this->requeueStrandedItems($items, $resolver, $itemRepository, $tempValidByToken);

		// Only the QUEUE row is re-read: a commit that stopped the queue again by quota - or
		// drained it to completed - wrote that row behind our back. The elements it moved
		// (committed, failed, duplicate_conflict) it also mutates in place on the collection
		// above, which is what the reconcile path of QueueRestoreProvider is built on, and so
		// does the requeue, so a second read of up to MAX_QUEUE_ITEMS rows would only fetch
		// what we already hold.
		// Two kinds of row are left out of step by that choice. One whose commit LOST its row
		// to a concurrent cancel - that canceller answers with the re-derived queue of its own.
		// And one the loop never touched at all (carriesFixableBytes() said no), which keeps
		// the snapshot taken BEFORE the loop and therefore travels in the event below as it was,
		// should a neighbouring tab have moved it meanwhile. Both self-heal with the next event
		// of that tab; the race predates this read and only its window grew.
		$queue = $queueRepository->getById((int)$queue->getId()) ?? $queue;

		$queueDto = QueueDto::fromEntities($queue, $items, $resolver, [], new QueueTargetNameResolver());
		$queuePayload = $queueDto->toArray();

		$this->notifier()->emit(
			$this->userId,
			(string)$queue->getId(),
			($queue->getStatus() ?? QueueStatus::Active)->value,
			$queuePayload['items'],
			$queuePayload['availableActions'],
			$queueDto->quotaPayload(),
			$queueDto->displayStatus,
		);

		$result->setData([
			'queue' => $queuePayload,
		]);

		return $result;
	}

	/**
	 * Commits the elements whose bytes are proven to still be in temp, and only those.
	 *
	 * Temp presence is asked for the whole queue in one batched pass
	 * ({@see TempPresenceProbe}, the route the restore takes) rather than per element: a
	 * queue holds up to {@see \Bitrix\Disk\Controller\DockedUploader\Queue::MAX_QUEUE_ITEMS}
	 * rows. The fixing itself goes through the regular {@see FileCommitService::commit()},
	 * with every gate it carries (per-item rights, temp owner re-validation, ALG-01,
	 * at-most-once side effects) - there is no lightweight commit path for a restore.
	 *
	 * That includes the BizProc parameter gate, and this call carries NO set: API-07 takes a
	 * queue id and nothing else, and the sets live on the client. In a storage whose active
	 * autostart template demands parameters the commit therefore ends in
	 * {@see FileCommitService::ERROR_BIZPROC_PARAMS_REQUIRED} rather than in a fixation. The
	 * outcome is chosen: that code is retriable, the temp token survives the failure, and the
	 * widget collects the set for the (storage, scenario) pair and requeues the rows, which
	 * are then re-committed from the bytes already in temp. Leaving such an element untouched
	 * would strand it instead - a row left in uploaded_to_temp is carried on by no one (see
	 * the accepted debt on {@see FileCommitService}).
	 *
	 * The pass ends as soon as the queue stops accepting a commit: a commit that hits the
	 * quota stops the queue again, and that closes the gate for the WHOLE queue (AC-041), so
	 * a smaller neighbour must not be fixed after it - the quota is asked per SIZE, and the
	 * space that did not fit the element just refused may well fit the next one. The gate is
	 * the durable queue STATUS ({@see QueueStatus::acceptsCommit()}), never the null a commit
	 * answers with: a refusal of the element itself (BizProc parameters, rights, a duplicate)
	 * stops no queue, and the pass carries on to its neighbours. The re-read is therefore paid
	 * on that null alone. Nothing is stranded by the exit either: the rows left untouched keep
	 * their status and are offered again by the next restore, which cannot be lost - a queue
	 * holding such a row does not drain to completed.
	 *
	 * @return array{0: array<string, bool>, 1: array<string, true>} the probe verdict
	 *   ({@see TempPresenceProbe::resolve()}), which the requeue reads after it
	 */
	private function commitConfirmedItems(
		DockedUploadItemCollection $items,
		DisplayStatusResolver $resolver,
		DockedUploadQueueRepositoryInterface $queueRepository,
	): array
	{
		$tokens = [];
		foreach ($items as $item)
		{
			if ($this->carriesFixableBytes($item, $resolver))
			{
				$tokens[] = (string)$item->getTempToken();
			}
		}

		if ($tokens === [])
		{
			return [[], []];
		}

		$verdict = $this->tempPresenceProbe->resolve($tokens, $this->userId);
		[$tempValidByToken] = $verdict;

		$commitService = $this->commitService
			?? ServiceLocator::getInstance()->get('disk.dockedUpload.fileCommitService');

		foreach ($items as $item)
		{
			if (!$this->carriesFixableBytes($item, $resolver))
			{
				continue;
			}
			// Unconfirmed is not the same as present: a token the batch could not verify
			// (the per-request ceiling, a malformed token) is left alone - the element then
			// keeps its status, which is what a restore does with it too.
			if (($tempValidByToken[(string)$item->getTempToken()] ?? false) !== true)
			{
				continue;
			}

			$committedObjectId = $commitService->commit($item);
			if ($committedObjectId === null && !$this->queueStillAcceptsCommit($queueRepository))
			{
				break;
			}
		}

		return $verdict;
	}

	/**
	 * Puts back into the queue the elements the stop left with no bytes to fix
	 * ({@see self::isStranded()}), so the client transfers them anew.
	 *
	 * The repository write carries the whole gate itself - the status, the token and a queue
	 * that still accepts a commit - so a commit of this pass that stopped the queue again, or
	 * a neighbouring tab that moved a row meanwhile, leaves those rows as they are. Only the
	 * rows that really made the transition are updated on the collection.
	 *
	 * @param array{0: array<string, bool>, 1: array<string, true>} $tempVerdict
	 */
	private function requeueStrandedItems(
		DockedUploadItemCollection $items,
		DisplayStatusResolver $resolver,
		DockedUploadItemRepository $itemRepository,
		array $tempVerdict,
	): void
	{
		$strandedIds = [];
		foreach ($items as $item)
		{
			if ($this->isStranded($item, $resolver, $tempVerdict))
			{
				$strandedIds[] = (int)$item->getId();
			}
		}

		if ($strandedIds === [])
		{
			return;
		}

		$moved = $itemRepository->markPendingFromStranded(
			$strandedIds,
			$this->userId,
			[DisplayStatusResolver::ERROR_QUOTA_CLOUD, DisplayStatusResolver::ERROR_QUOTA_BOX],
		);
		if ($moved === 0)
		{
			return;
		}

		$movedIds = array_fill_keys($strandedIds, true);
		if ($moved < count($strandedIds))
		{
			foreach ($itemRepository->getByIds($strandedIds, $this->userId) as $fresh)
			{
				if ($fresh->getStatus() !== ItemStatus::Pending)
				{
					unset($movedIds[(int)$fresh->getId()]);
				}
			}
		}

		foreach ($items as $item)
		{
			if (isset($movedIds[(int)$item->getId()]))
			{
				$item->setStatus(ItemStatus::Pending);
				$item->setErrorCode(null);
				$item->setTempToken(null);
			}
		}
	}

	/**
	 * Whether the element holds nothing the restore can fix and nothing else will ever move:
	 * a quota failure or an uploading row whose temp is absent - no token at all, or one the
	 * probe answered as gone. A token the probe could not verify (the per-request ceiling)
	 * is left alone, the way the commit pass leaves it.
	 *
	 * @param array{0: array<string, bool>, 1: array<string, true>} $tempVerdict
	 */
	private function isStranded(DockedUploadItem $item, DisplayStatusResolver $resolver, array $tempVerdict): bool
	{
		if ($item->isFolder())
		{
			return false;
		}

		$tempToken = (string)$item->getTempToken();
		if ($tempToken !== '')
		{
			[$tempValidByToken, $uncheckedTokens] = $tempVerdict;
			if (($tempValidByToken[$tempToken] ?? false) === true || isset($uncheckedTokens[$tempToken]))
			{
				return false;
			}
		}

		return match ($item->getStatus())
		{
			ItemStatus::Failed => $resolver->isQuotaStopCode($item->getErrorCode()),
			ItemStatus::Uploading => $tempToken === '',
			default => false,
		};
	}

	/**
	 * Whether the queue still takes a commit, asked of the durable row: a stop written by a
	 * commit of this very pass - or by a neighbouring tab meanwhile - is not on the entity
	 * the pass carries.
	 */
	private function queueStillAcceptsCommit(DockedUploadQueueRepositoryInterface $queueRepository): bool
	{
		$queue = $queueRepository->getById($this->queueId);

		return $queue !== null && ($queue->getStatus() ?? QueueStatus::Active)->acceptsCommit();
	}

	/**
	 * Whether the element is one whose transferred bytes the restore may fix.
	 *
	 * Two groups qualify, and both hold bytes the user has already paid the traffic for:
	 * an element that reached temp (its commit never ran - the stop closed the gate), and
	 * one that failed with a QUOTA code. The latter is not a fault of the file but the
	 * refusal of the QUEUE for want of space - mechanism 2 hits on the commit, so its temp
	 * is alive - and no other way out exists for it, a quota code being non-retriable by
	 * construction.
	 *
	 * Everything else is left untouched, deliberately: a pending element is the tail the
	 * client transfers itself, a duplicate_conflict waits for a decision only the user
	 * makes ({@see ResolveConflictCommand}), and interrupted_needs_file has no bytes to
	 * fix at all.
	 */
	private function carriesFixableBytes(DockedUploadItem $item, DisplayStatusResolver $resolver): bool
	{
		if ($item->isFolder())
		{
			return false;
		}

		$tempToken = $item->getTempToken();
		if ($tempToken === null || $tempToken === '')
		{
			return false;
		}

		return match ($item->getStatus())
		{
			ItemStatus::Uploading, ItemStatus::UploadedToTemp => true,
			ItemStatus::Failed => $resolver->isQuotaStopCode($item->getErrorCode()),
			default => false,
		};
	}

	private function notifier(): QueueEventNotifier
	{
		return $this->eventNotifier
			?? ServiceLocator::getInstance()->get('disk.dockedUpload.queueEventNotifier');
	}
}
