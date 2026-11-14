<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\DockedUpload;

use Bitrix\Disk\Internal\Dto\DockedUpload\QueueDto;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItem;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItemCollection;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadQueue;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;
use Bitrix\Disk\Internal\Enum\DockedUpload\QueueStatus;
use Bitrix\Disk\Internal\Repository\DockedUpload\DockedUploadItemRepository;
use Bitrix\Disk\Internal\Repository\Interface\DockedUploadQueueRepositoryInterface;
use Bitrix\Disk\Internal\Service\DockedUpload\DisplayStatusResolver;
use Bitrix\Disk\Internal\Service\DockedUpload\DurableOrphanReleaser;
use Bitrix\Disk\Internal\Service\DockedUpload\QueueEventNotifier;
use Bitrix\Disk\Internal\Service\DockedUpload\TempPresenceProbe;
use Bitrix\Disk\Internal\Service\DockedUpload\UnexpectedErrorReporter;
use Bitrix\Main\Application;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;
use Bitrix\UI\FileUploader\Uploader;
use Throwable;

/**
 * Confirmed close of the whole widget (API-01 dismiss, SC-015).
 *
 * The request is addressed to the WIDGET, not to a queue: it carries no queueId and
 * applies to every queue of the user at once (AC-075/086). In one operation the
 * unfinished elements of them all become canceled (unfinished = everything outside
 * committed / canceled, failed, duplicate_conflict and interrupted_needs_file included),
 * the unfinished queues become dismissed and the already finished ones stay completed -
 * neither group is ever brought back by a restore afterwards (AC-077).
 *
 * There is no "no active transfer" gate: a running transfer does not forbid the close, it
 * makes a confirmation necessary - and the confirmation is asked by the CLIENT (AC-080/087).
 * The responsibilities are split on purpose: the client owns the dialog and the decision,
 * the server owns the atomic execution and never imitates a confirmation of its own.
 *
 * A committed DISK_OBJECT_ID is never touched and the workflows already started for the
 * successful files are neither cancelled nor restarted (AC-092); the temp files of the
 * canceled elements are freed as soon as the answer is out rather than at the 24h TTL -
 * as far as one ownership probe reaches, the tail beyond its per-request ceiling staying
 * with that TTL ({@see self::releaseTemp()}). What a crash left half-written is collected
 * with them, on the same rule the per-queue cancel follows ({@see DurableOrphanReleaser}):
 * a dismissed queue never comes back through a restore and the TTL agent skips a canceled
 * row, so nothing else would ever pick that artifact up.
 */
class DismissCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $userId,
		private readonly ?DockedUploadItemRepository $itemRepository = null,
		private readonly ?DockedUploadQueueRepositoryInterface $queueRepository = null,
		private readonly ?DisplayStatusResolver $resolver = null,
		private readonly ?Uploader $uploader = null,
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

		// The queues the widget holds - every unfinished one of the user, whatever its
		// EXPIRES_AT says. That is wider than the set a restore would bring back, and it has
		// to be: what a queue may still commit is its STATUS alone, so an expired queue a tab
		// older than the TTL still holds would be left open here and carried on to a file by
		// that tab. The finished ones are not read and not written: they stay completed
		// (AC-086). Nothing to close is a success, not an error: closing an empty widget is
		// idempotent.
		$queues = $queueRepository->getUnfinishedByUser($this->userId);
		if ($queues->isEmpty())
		{
			$result->setData(['ok' => true]);

			return $result;
		}

		$queueIds = [];
		foreach ($queues as $queue)
		{
			$queueIds[] = (int)$queue->getId();
		}

		// One read of every row of every closed queue: it supplies the temp tokens to free
		// AND the state the events are built from, so neither grows into a per-queue read.
		// The tokens are kept per queue because the scope of the close is only decided
		// below: freeing the temp of a queue whose rows stay alive would strand them.
		/** @var array<int, DockedUploadItemCollection> $itemsByQueue */
		$itemsByQueue = [];
		/** @var array<int, string[]> $tokensByQueue */
		$tokensByQueue = [];
		// The elements the close is about to cancel, per queue: the same scoping rule as
		// the tokens, and for the same reason - a queue whose rows survive must keep its
		// artifacts too.
		/** @var array<int, DockedUploadItem[]> $canceledByQueue */
		$canceledByQueue = [];
		foreach ($itemRepository->getByQueuesForDismiss($queueIds, $this->userId) as $item)
		{
			$queueId = (int)$item->getQueueId();
			($itemsByQueue[$queueId] ??= new DockedUploadItemCollection())->add($item);

			if (!self::isTerminal($item->getStatus()))
			{
				$canceledByQueue[$queueId][] = $item;

				$tempToken = $item->getTempToken();
				if ($tempToken !== null && $tempToken !== '')
				{
					$tokensByQueue[$queueId][] = $tempToken;
				}
			}
		}

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			// The queues go first because their guarded UPDATE is what DECIDES the scope of
			// the close: a queue that drained to completed meanwhile keeps that state and
			// its history (AC-086), and everything below then leaves it alone - its rows
			// keep their statuses, its temp keeps its bytes and no close event names it.
			// Hence the read in between: the guard, not the snapshot taken before the
			// transaction, says which queues were closed. Three statements for the whole
			// widget, whatever the number of queues.
			$queueRepository->markDismissedByIds($queueIds, $this->userId);
			$dismissedIds = $queueRepository->findDismissedIds($queueIds, $this->userId);

			$itemRepository->updateStatusByQueues(
				$dismissedIds,
				ItemStatus::Canceled,
				[ItemStatus::Committed, ItemStatus::Canceled],
				userId: $this->userId,
			);

			$connection->commitTransaction();
		}
		catch (Throwable $exception)
		{
			$connection->rollbackTransaction();

			return $result->addError(UnexpectedErrorReporter::report($exception, 'dismiss'));
		}

		// Freeing temp files is external, best-effort and says nothing to the answer, so it
		// is not paid by the click: the close is durable by now and the work goes past the
		// response, with the TTL agent the backstop it always was. Out of the transaction
		// too, so a storage hiccup never rolls the close back (the rule cancel follows).
		$tokensToRelease = self::collectTokens($tokensByQueue, $dismissedIds);
		if ($tokensToRelease !== [])
		{
			$this->deferPastAnswer(fn() => $this->releaseTemp($tokensToRelease));
		}

		// The other half of what a cancellation frees, and for exactly as long the close
		// did not free it: an element whose domain write went through before a crash left
		// a live object in the user's folder carrying its INTENT_TOKEN. A dismissed queue
		// is never restored and the TTL agent skips a canceled row, so this is the last
		// moment anything can collect it ({@see DurableOrphanReleaser}). Deferred like the
		// temp - the close is durable by now and the click pays for neither.
		$orphanCandidates = self::collectCanceled($canceledByQueue, $dismissedIds);
		if ($orphanCandidates !== [])
		{
			$this->deferPastAnswer(
				fn() => $this->orphanReleaser()->release($orphanCandidates, $itemRepository, $this->userId),
			);
		}

		$this->emitClosedQueues($queues, $dismissedIds, $itemsByQueue, $resolver);

		$result->setData(['ok' => true]);

		return $result;
	}

	/**
	 * One pull event per CLOSED queue, built from the rows already read.
	 *
	 * Only the queues the status guard actually dismissed are announced: the snapshot the
	 * events are built from was taken before the transaction, and a queue that drained to
	 * completed in between stays completed in the database - telling its tabs it was
	 * dismissed would state the opposite of the row (AC-086).
	 *
	 * {@see \Bitrix\Disk\Internal\Service\DockedUpload\QueueChangeProjector} is deliberately
	 * not used here: it exists for a caller that knows one changed element and not the
	 * queue around it, and its flat-queue branch answers with existence probes - four of
	 * them PER queue, which is exactly the growth this command must not have. Every row is
	 * already in memory, so the same aggregation ({@see QueueDto::fromEntities()}, the very
	 * one the projector delegates to) costs nothing here.
	 *
	 * @param iterable<DockedUploadQueue> $queues
	 * @param int[] $dismissedQueueIds
	 * @param array<int, DockedUploadItemCollection> $itemsByQueue
	 */
	private function emitClosedQueues(
		iterable $queues,
		array $dismissedQueueIds,
		array $itemsByQueue,
		DisplayStatusResolver $resolver,
	): void
	{
		if ($dismissedQueueIds === [])
		{
			return;
		}

		$notifier = $this->eventNotifier
			?? ServiceLocator::getInstance()->get('disk.dockedUpload.queueEventNotifier');

		$dismissed = array_flip($dismissedQueueIds);
		foreach ($queues as $queue)
		{
			$queueId = (int)$queue->getId();
			if (!isset($dismissed[$queueId]))
			{
				continue;
			}

			$items = $itemsByQueue[$queueId] ?? new DockedUploadItemCollection();
			foreach ($items as $item)
			{
				if (!self::isTerminal($item->getStatus()))
				{
					$item->setStatus(ItemStatus::Canceled);
				}
			}
			$queue->setStatus(QueueStatus::Dismissed);

			$queueDto = QueueDto::fromEntities($queue, $items, $resolver);
			$payload = $queueDto->toArray();

			$notifier->emit(
				$this->userId,
				(string)$queueId,
				QueueStatus::Dismissed->value,
				$payload['items'],
				$payload['availableActions'],
				// No quota verdict travels with a close: the queue leaves the widget along
				// with its banner, so this event says nothing about the classification
				// rather than reporting the "no quota stop" every cancellation would leave
				// behind (the key-presence rule of the notifier).
				null,
				$queueDto->displayStatus,
			);
		}
	}

	/**
	 * The temp tokens of the queues the close actually ended, in one flat list.
	 *
	 * @param array<int, string[]> $tokensByQueue
	 * @param int[] $queueIds
	 * @return string[]
	 */
	private static function collectTokens(array $tokensByQueue, array $queueIds): array
	{
		$tokens = [];
		foreach ($queueIds as $queueId)
		{
			foreach ($tokensByQueue[$queueId] ?? [] as $token)
			{
				$tokens[] = $token;
			}
		}

		return $tokens;
	}

	/**
	 * The elements the close actually canceled, in one flat list.
	 *
	 * @param array<int, DockedUploadItem[]> $canceledByQueue
	 * @param int[] $queueIds
	 * @return DockedUploadItem[]
	 */
	private static function collectCanceled(array $canceledByQueue, array $queueIds): array
	{
		$canceled = [];
		foreach ($queueIds as $queueId)
		{
			foreach ($canceledByQueue[$queueId] ?? [] as $item)
			{
				$canceled[] = $item;
			}
		}

		return $canceled;
	}

	private function orphanReleaser(): DurableOrphanReleaser
	{
		return new DurableOrphanReleaser();
	}

	/**
	 * Hands $job to the runner that executes it once the answer is out.
	 *
	 * Kept as the one transport seam of the close, in the role
	 * {@see \Bitrix\Disk\Internal\Service\DockedUpload\FileCommitService::readStorageSizeLimit()}
	 * plays for the size limit: {@see Application::runBackgroundJobs()} does not run what a
	 * caller deferred, it drains the queue of the whole PROCESS - the kernel fills that queue
	 * on initialisation, the agent scheduler among the jobs - so there is no way to execute
	 * this one job through the runner alone. A test overrides the seam and executes exactly
	 * what the close deferred.
	 */
	protected function deferPastAnswer(callable $job): void
	{
		Application::getInstance()->addBackgroundJob($job);
	}

	/**
	 * Frees the temp files of the canceled elements - the ones that are the caller's own.
	 *
	 * The ownership check is ours to make: {@see Uploader::remove()} deletes a temp row by
	 * the GUID inside the token and never compares CREATED_BY, while the token on a row is
	 * whatever the client wrote there (updateItem stores it as given), so nothing else ties
	 * the bytes about to be dropped to their owner. {@see TempPresenceProbe::filterOwnPresent()}
	 * answers that for the whole batch in a couple of queries with the same '=CREATED_BY'
	 * filter the commit path validates a temp file with
	 * ({@see \Bitrix\Disk\Internal\Service\DockedUpload\FileCommitService} owner_mismatch),
	 * which is what makes the release symmetric with the fixation. The same filter - not a
	 * copy of it - guards the other two releases of the surface
	 * ({@see CancelCommand::releaseTemp()}, {@see ResolveConflictCommand::releaseTemp()}).
	 *
	 * A token the probe leaves unverified (its per-request ceiling) is not freed here: the
	 * TTL agent stays the backstop for that tail, exactly as it is for a failure below.
	 *
	 * @param string[] $tempTokens
	 */
	private function releaseTemp(array $tempTokens): void
	{
		if ($tempTokens === [])
		{
			return;
		}

		try
		{
			$ownTokens = $this->tempPresenceProbe->filterOwnPresent($tempTokens, $this->userId);
			if ($ownTokens === [])
			{
				return;
			}

			$uploader = $this->uploader ?? ServiceLocator::getInstance()->get('disk.dockedUpload.uploader');
			$uploader->remove($ownTokens);
		}
		catch (Throwable)
		{
			// Freeing a temp file is best-effort; the TTL agent is the backstop.
		}
	}

	private static function isTerminal(?ItemStatus $status): bool
	{
		return $status === ItemStatus::Committed || $status === ItemStatus::Canceled;
	}
}
