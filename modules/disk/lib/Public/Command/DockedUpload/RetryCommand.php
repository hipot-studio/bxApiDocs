<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\DockedUpload;

use Bitrix\Disk\Internal\Dto\DockedUpload\ItemDto;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItem;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadQueue;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;
use Bitrix\Disk\Internal\Enum\DockedUpload\QueueStatus;
use Bitrix\Disk\Internal\Repository\DockedUpload\DockedUploadItemRepository;
use Bitrix\Disk\Internal\Repository\Interface\DockedUploadQueueRepositoryInterface;
use Bitrix\Disk\Internal\Service\DockedUpload\DisplayStatusResolver;
use Bitrix\Disk\Internal\Service\DockedUpload\QueueChangeProjector;
use Bitrix\Disk\Internal\Service\DockedUpload\QueueEventNotifier;
use Bitrix\Disk\Internal\Service\DockedUpload\UnexpectedErrorReporter;
use Bitrix\Main\Application;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

/**
 * Re-queues failed items for another attempt (API-01 retry).
 *
 * Only ItemStatus.failed with a retriable ERROR_CODE (generic transport errors)
 * moves back to pending; quota / permission / restriction / size / blocked, and
 * canceled / committed are skipped. A queue that refuses retries
 * ({@see DisplayStatusResolver::acceptsRetry()}) is never bypassed: no retry path -
 * single item, folder cascade, the scope:failed batch (AC-076) or the itemIds batch -
 * moves anything in a queue stopped in place or closed. The batch additionally acts on
 * the named queue alone when the caller passes a queueId.
 *
 * itemIds (CONTRACT-API-06) is the batch form of the single-item branch: the same
 * gates per named id, the unfit ids silently skipped, one batch flip for the rest.
 * It is mutually exclusive with itemId and scope - combined addressing is refused.
 */
class RetryCommand extends AbstractCommand
{
	public const SCOPE_FAILED = 'failed';

	/**
	 * @param int[]|null $itemIds
	 */
	public function __construct(
		public readonly int $userId,
		public readonly ?int $itemId = null,
		public readonly ?string $scope = null,
		public readonly ?int $queueId = null,
		public readonly ?array $itemIds = null,
		private readonly ?DockedUploadItemRepository $itemRepository = null,
		private readonly ?DockedUploadQueueRepositoryInterface $queueRepository = null,
		private readonly ?DisplayStatusResolver $resolver = null,
		private readonly ?QueueEventNotifier $eventNotifier = null,
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

		// itemIds is a self-contained batch address: combined with the single-item or
		// the scope form the intent is ambiguous, and guessing would retry rows the
		// user did not point at.
		if ($this->itemIds !== null && ($this->itemId !== null || $this->scope !== null))
		{
			return $result->addError(new Error('Ambiguous retry target', 'DISK_DOCKED_RETRY_TARGET_AMBIGUOUS'));
		}

		// Deferred pull emits: collected inside the transaction, dispatched only
		// after a successful commit (best-effort, never inside the transaction).
		$emits = [];

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$retried = match (true)
			{
				$this->itemId !== null => $this->retrySingle($itemRepository, $queueRepository, $resolver, $result, $emits),
				$this->itemIds !== null => $this->retryItemIds($itemRepository, $queueRepository, $resolver, $emits),
				default => $this->retryScope($itemRepository, $queueRepository, $resolver, $result, $emits),
			};

			if (!$result->isSuccess())
			{
				$connection->rollbackTransaction();

				return $result;
			}

			$connection->commitTransaction();
		}
		catch (Throwable $exception)
		{
			$connection->rollbackTransaction();

			return $result->addError(UnexpectedErrorReporter::report($exception, 'retry'));
		}

		// Re-queuing files inside a folder moves that folder's aggregate and the actions
		// of every queue touched (a queue with nothing failed left drops retryFailed), so
		// both travel with the answer and the pull event. scope:failed spans queues, hence
		// the queueId-keyed action map - and one batched read for all of them (PL2).
		$projections = (new QueueChangeProjector($itemRepository, $resolver))->projectMany(
			$this->queuesOf($emits),
			$this->userId,
			$this->idsOf($retried),
		);

		$folderItems = [];
		$queueActions = [];
		$queueQuota = [];
		$queueStatuses = [];
		foreach ($emits as [$queue, $items])
		{
			$projection = $projections[(int)$queue->getId()] ?? ['folderItems' => [], 'queueActions' => []];
			$folderItems = array_merge($folderItems, $projection['folderItems']);
			$queueActions[(string)$queue->getId()] = $projection['queueActions'];
			// A queue with no projection is not reported as "no quota stop": that verdict
			// drives the banner CTA, so an unknown one is left out for the client to keep
			// what it has.
			if (isset($projection['queueQuota']))
			{
				$queueQuota[(string)$queue->getId()] = $projection['queueQuota'];
			}

			// Re-queuing the failed elements is what takes a queue out of "waiting on the
			// user", so the answer carries that transition itself - the same pair
			// {@see self::emitRetried()} sends to the neighbouring tabs.
			$queueStatuses[(string)$queue->getId()] = [
				'status' => ($queue->getStatus() ?? QueueStatus::Active)->value,
				'displayStatus' => $projection['queueDisplayStatus'] ?? null,
			];

			$this->emitRetried($queue, $items, $resolver, $projection);
		}

		$result->setData([
			'items' => array_map(
				static fn(DockedUploadItem $item) => ItemDto::fromFileItem($item, QueueStatus::Active, $resolver)->toArray(),
				$retried,
			),
			'folderItems' => $folderItems,
			'queueActions' => $queueActions,
			'queueQuota' => $queueQuota,
			'queueStatuses' => $queueStatuses,
		]);

		return $result;
	}

	/**
	 * @return DockedUploadItem[]
	 */
	private function retrySingle(
		DockedUploadItemRepository $itemRepository,
		DockedUploadQueueRepositoryInterface $queueRepository,
		DisplayStatusResolver $resolver,
		Result $result,
		array &$emits,
	): array
	{
		$item = $itemRepository->getById((int)$this->itemId);
		$queue = $item !== null ? $queueRepository->getById((int)$item->getQueueId()) : null;
		if ($item === null || $queue === null || (int)$queue->getUserId() !== $this->userId)
		{
			$result->addError(new Error('Item not found', 'DISK_DOCKED_ITEM_NOT_FOUND'));

			return [];
		}

		if ($item->isFolder())
		{
			return $this->retryFolderCascade($itemRepository, $item, $queue, $resolver, $emits);
		}

		// A queue that refuses retries is not bypassed by addressing a single file either:
		// the folder cascade above and the scope:failed batch both refuse it, and a lone
		// leaf is no different - a queue stopped in place waits for an explicit restore
		// (API-07), so the item would only sit in pending until then. That covers both
		// stops (AC-016/091) and, just as much, a queue the user dismissed: restore never
		// brings it back, so the work would run for a screen nobody can reach again. An
		// idempotent no-op, mirroring the empty cascade, not an error - a stale tab is not
		// told off about a queue it no longer shows.
		if (!$resolver->acceptsRetry($queue->getStatus() ?? QueueStatus::Active))
		{
			return [];
		}

		if (!$this->retryItem($item, $resolver, $itemRepository))
		{
			return [];
		}

		$emits[] = [$queue, [$item]];

		return [$item];
	}

	/**
	 * Retrying a folder-item re-queues the retriable failed FILES in its sub-tree.
	 * Its own row is never among them by construction: {@see self::collectFileDescendants()}
	 * takes the folder-item as the ROOT of the walk and returns only the children that are
	 * not folders, so the batch holds leaves alone whatever status the grouping row carries -
	 * its own failure included, and a folder the enqueue refused does carry one
	 * ({@see \Bitrix\Disk\Internal\Dto\DockedUpload\ItemDto::fromFolderItem()} publishes it
	 * where the sub-tree has nothing to say). Such a refusal is non-retriable, so no retry is
	 * offered on that row to begin with.
	 *
	 * A queue that refuses retries re-queues nothing (mirrors retryScope): neither stop in
	 * place nor a dismissal is walked around through the cascade. An empty cascade is an
	 * idempotent no-op, not an error.
	 *
	 * @return DockedUploadItem[]
	 */
	private function retryFolderCascade(
		DockedUploadItemRepository $itemRepository,
		DockedUploadItem $folderItem,
		DockedUploadQueue $queue,
		DisplayStatusResolver $resolver,
		array &$emits,
	): array
	{
		if (!$resolver->acceptsRetry($queue->getStatus() ?? QueueStatus::Active))
		{
			return [];
		}

		$retriableIds = [];
		foreach ($this->collectFileDescendants($itemRepository, (int)$folderItem->getId()) as $file)
		{
			if ($file->getStatus() === ItemStatus::Failed && $resolver->isRetriable($file->getErrorCode()))
			{
				$retriableIds[] = (int)$file->getId();
			}
		}

		if ($retriableIds === [])
		{
			return [];
		}

		// Same guarded CAS as the other batch branch: the ids come from a sub-tree snapshot,
		// so only the rows still failed are re-queued and only they are reported.
		if ($itemRepository->markPendingFromFailedByIds($retriableIds, $this->userId) === 0)
		{
			return [];
		}

		$retried = [];
		foreach ($itemRepository->getByIds($retriableIds, $this->userId) as $file)
		{
			if ($file->getStatus() === ItemStatus::Pending)
			{
				$retried[] = $file;
			}
		}

		if ($retried !== [])
		{
			$emits[] = [$queue, $retried];
		}

		return $retried;
	}

	/**
	 * Collects every file item in the sub-tree rooted at $folderItemId, walking the
	 * nested folder-items level by level with one batched read per level (no N+1),
	 * mirroring FolderTreeBuilder::collectFileDescendants().
	 *
	 * @return DockedUploadItem[]
	 */
	private function collectFileDescendants(DockedUploadItemRepository $itemRepository, int $folderItemId): array
	{
		$files = [];
		$level = [$folderItemId];
		$seenFolders = [$folderItemId => true];

		while ($level !== [])
		{
			$nextLevel = [];
			foreach ($itemRepository->getByParentItems($level, $this->userId) as $child)
			{
				if (!$child->isFolder())
				{
					$files[] = $child;

					continue;
				}

				$childId = (int)$child->getId();
				if (!isset($seenFolders[$childId]))
				{
					$seenFolders[$childId] = true;
					$nextLevel[] = $childId;
				}
			}

			$level = $nextLevel;
		}

		return $files;
	}

	/**
	 * scope:failed (AC-076) - retries the failed items of the queue the caller names,
	 * or of all the user's queues when none is named. A stop in place is never bypassed:
	 * such a queue is skipped whole.
	 *
	 * @return DockedUploadItem[]
	 */
	private function retryScope(
		DockedUploadItemRepository $itemRepository,
		DockedUploadQueueRepositoryInterface $queueRepository,
		DisplayStatusResolver $resolver,
		Result $result,
		array &$emits,
	): array
	{
		if ($this->scope !== self::SCOPE_FAILED)
		{
			$result->addError(new Error('Nothing to retry', 'DISK_DOCKED_RETRY_NO_TARGET'));

			return [];
		}

		$queues = $this->resolveScopeQueues($queueRepository, $resolver, $result);
		if ($queues === [])
		{
			return [];
		}

		// One batched read for every retriable queue instead of a getByQueue per queue
		// (PL2); grouping back by queue is done in memory. Only the FAILED elements are
		// read, and of them only the columns the gate below reads: everything else in the
		// queue is a row this loop would skip.
		$itemsByQueue = [];
		foreach ($itemRepository->getFailedByQueues(array_keys($queues), $this->userId) as $item)
		{
			$itemsByQueue[(int)$item->getQueueId()][] = $item;
		}

		// Resolve which failed items are retriable purely in memory (the business
		// rule lives in the resolver, not in SQL), then flip them ALL with a
		// single batch UPDATE instead of one round-trip per item (PL2/perf, up to
		// 1000 UPDATEs previously).
		$retriableIdsByQueue = [];
		$allRetriableIds = [];
		foreach ($queues as $queueId => $queue)
		{
			foreach ($itemsByQueue[$queueId] ?? [] as $item)
			{
				if ($item->getStatus() !== ItemStatus::Failed || !$resolver->isRetriable($item->getErrorCode()))
				{
					continue;
				}

				$id = (int)$item->getId();
				$retriableIdsByQueue[$queueId][] = $id;
				$allRetriableIds[] = $id;
			}
		}

		return $this->requeueRetriable($itemRepository, $retriableIdsByQueue, $allRetriableIds, $queues, $emits);
	}

	/**
	 * The itemIds batch (CONTRACT-API-06) - the batch form of the single-file branch:
	 * every named id passes the same gates - its own user, a queue that accepts retries,
	 * ItemStatus.failed with a retriable code - and the unfit ids (non-retriable,
	 * terminal, foreign, in a closed queue or one stopped in place) are silently skipped:
	 * the answer carries the rows actually re-queued. The whole batch costs one queue
	 * read, one row read, one batch UPDATE and one re-read (PL2: nothing per item).
	 *
	 * @return DockedUploadItem[]
	 */
	private function retryItemIds(
		DockedUploadItemRepository $itemRepository,
		DockedUploadQueueRepositoryInterface $queueRepository,
		DisplayStatusResolver $resolver,
		array &$emits,
	): array
	{
		$ids = array_values(array_unique(array_filter(
			array_map('intval', $this->itemIds ?? []),
			static fn(int $id): bool => $id > 0,
		)));
		if ($ids === [])
		{
			return [];
		}

		// The queues that accept retries, read once for the whole batch. A row of a
		// completed/dismissed queue is absent from the map and one stopped in place is
		// refused by the gate - either way its rows are skipped, never worked around.
		$workingQueues = [];
		foreach ($queueRepository->getActiveByUser($this->userId) as $queue)
		{
			if ($resolver->acceptsRetry($queue->getStatus() ?? QueueStatus::Active))
			{
				$workingQueues[(int)$queue->getId()] = $queue;
			}
		}
		if ($workingQueues === [])
		{
			return [];
		}

		// One batched read of the named rows; a foreign id never comes back (the read
		// is user-scoped), so it is skipped by construction, not by a check.
		$retriableIdsByQueue = [];
		$allRetriableIds = [];
		foreach ($itemRepository->getByIds($ids, $this->userId) as $item)
		{
			$queueId = (int)$item->getQueueId();
			if (!isset($workingQueues[$queueId]))
			{
				continue;
			}
			if ($item->getStatus() !== ItemStatus::Failed || !$resolver->isRetriable($item->getErrorCode()))
			{
				continue;
			}

			$id = (int)$item->getId();
			$retriableIdsByQueue[$queueId][] = $id;
			$allRetriableIds[] = $id;
		}

		return $this->requeueRetriable($itemRepository, $retriableIdsByQueue, $allRetriableIds, $workingQueues, $emits);
	}

	/**
	 * The shared tail of both batch branches (scope:failed and itemIds): flips all the
	 * gated ids with a single batch CAS, re-reads exactly the changed rows with one
	 * whereIn query - fresh DTOs instead of the pre-update in-memory copies - and
	 * groups the fresh entities back by queue for the pull emits (PL2: nothing per item).
	 *
	 * The ids are gated against an in-memory snapshot, so the UPDATE carries the same
	 * STATUS = 'failed' guard the single-item retry does and the answer keeps only the rows
	 * that really came back pending - a row that left failed meanwhile is neither dragged
	 * back nor reported as re-queued.
	 *
	 * @param array<int, int[]> $retriableIdsByQueue
	 * @param int[] $allRetriableIds
	 * @param array<int, DockedUploadQueue> $queuesById keyed by queue id
	 * @return DockedUploadItem[]
	 */
	private function requeueRetriable(
		DockedUploadItemRepository $itemRepository,
		array $retriableIdsByQueue,
		array $allRetriableIds,
		array $queuesById,
		array &$emits,
	): array
	{
		if ($allRetriableIds === [])
		{
			return [];
		}

		if ($itemRepository->markPendingFromFailedByIds($allRetriableIds, $this->userId) === 0)
		{
			return [];
		}

		$updatedById = [];
		foreach ($itemRepository->getByIds($allRetriableIds, $this->userId) as $item)
		{
			if ($item->getStatus() === ItemStatus::Pending)
			{
				$updatedById[(int)$item->getId()] = $item;
			}
		}

		$retried = [];
		foreach ($retriableIdsByQueue as $queueId => $ids)
		{
			$queueRetried = [];
			foreach ($ids as $id)
			{
				$item = $updatedById[$id] ?? null;
				if ($item !== null)
				{
					$retried[] = $item;
					$queueRetried[] = $item;
				}
			}

			if ($queueRetried !== [])
			{
				$emits[] = [$queuesById[$queueId], $queueRetried];
			}
		}

		return $retried;
	}

	/**
	 * The queues a scope:failed batch acts on.
	 *
	 * The retry-all affordance is rendered on ONE queue, so a named queueId narrows the
	 * batch to it - restarting a neighbouring queue from another queue's banner is not
	 * what the user asked for. Without a queueId the pre-addressing behaviour stays (all
	 * of the user's queues). Either way only a queue that accepts retries is acted on:
	 * getActiveByUser also returns the ones stopped in place, and the stop is never
	 * bypassed. An unknown or foreign queueId is an explicit error, not a silently empty
	 * answer.
	 *
	 * @return array<int, DockedUploadQueue> keyed by queue id
	 */
	private function resolveScopeQueues(
		DockedUploadQueueRepositoryInterface $queueRepository,
		DisplayStatusResolver $resolver,
		Result $result,
	): array
	{
		if ($this->queueId !== null)
		{
			$queue = $queueRepository->getById($this->queueId);
			if ($queue === null || (int)$queue->getUserId() !== $this->userId)
			{
				$result->addError(new Error('Queue not found', 'DISK_DOCKED_QUEUE_NOT_FOUND'));

				return [];
			}

			return $resolver->acceptsRetry($queue->getStatus() ?? QueueStatus::Active)
				? [(int)$queue->getId() => $queue]
				: []
			;
		}

		$queues = [];
		foreach ($queueRepository->getActiveByUser($this->userId) as $queue)
		{
			if (!$resolver->acceptsRetry($queue->getStatus() ?? QueueStatus::Active))
			{
				continue;
			}

			$queues[(int)$queue->getId()] = $queue;
		}

		return $queues;
	}

	/**
	 * @param DockedUploadItem[] $items
	 * @param array{
	 *   folderItems: array<int, array>,
	 *   queueDisplayStatus?: string,
	 *   queueActions: string[],
	 *   queueQuota?: array{quotaScenario: string|null, infoHelperCode: string|null},
	 * } $projection
	 */
	private function emitRetried(
		DockedUploadQueue $queue,
		array $items,
		DisplayStatusResolver $resolver,
		array $projection,
	): void
	{
		$queueStatus = $queue->getStatus() ?? QueueStatus::Active;
		$dtos = array_map(
			static fn(DockedUploadItem $item) => ItemDto::fromFileItem($item, $queueStatus, $resolver)->toArray(),
			$items,
		);

		$notifier = $this->eventNotifier
			?? ServiceLocator::getInstance()->get('disk.dockedUpload.queueEventNotifier');
		$notifier->emit(
			$this->userId,
			(string)$queue->getId(),
			$queueStatus->value,
			array_merge($dtos, $projection['folderItems']),
			$projection['queueActions'],
			$projection['queueQuota'] ?? null,
			$projection['queueDisplayStatus'] ?? null,
		);
	}

	/**
	 * @param DockedUploadItem[] $items
	 * @return int[]
	 */
	private function idsOf(array $items): array
	{
		return array_map(static fn(DockedUploadItem $item): int => (int)$item->getId(), $items);
	}

	/**
	 * @param array<int, array{0: DockedUploadQueue, 1: DockedUploadItem[]}> $emits
	 * @return array<int, DockedUploadQueue> keyed by queue id
	 */
	private function queuesOf(array $emits): array
	{
		$queues = [];
		foreach ($emits as [$queue, $items])
		{
			$queues[(int)$queue->getId()] = $queue;
		}

		return $queues;
	}

	private function retryItem(
		DockedUploadItem $item,
		DisplayStatusResolver $resolver,
		DockedUploadItemRepository $itemRepository,
	): bool
	{
		if ($item->getStatus() !== ItemStatus::Failed || !$resolver->isRetriable($item->getErrorCode()))
		{
			return false;
		}

		// Atomic failed -> pending guarded on STATUS = failed instead of a full-row save,
		// so a retry racing a concurrent cancel of the same item cannot resurrect a
		// canceled (or already-committed) row from this stale snapshot.
		if (!$itemRepository->markPendingFromFailed((int)$item->getId(), $this->userId))
		{
			return false;
		}

		$item->setStatus(ItemStatus::Pending)->setErrorCode(null);

		return true;
	}
}
