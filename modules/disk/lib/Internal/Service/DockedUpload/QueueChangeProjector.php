<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

use Bitrix\Disk\Internal\Dto\DockedUpload\ItemDto;
use Bitrix\Disk\Internal\Dto\DockedUpload\QueueDto;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItem;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItemCollection;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadQueue;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemType;
use Bitrix\Disk\Internal\Enum\DockedUpload\QueueStatus;
use Bitrix\Disk\Internal\Repository\DockedUpload\DockedUploadItemRepository;

/**
 * What an item-level mutation changes BESIDE the item it names: the folder-items
 * that own it (their aggregate moved), the queue action list and the queue-level quota
 * classification.
 *
 * A single-item answer (updateItem / retry / resolveConflict) used to carry the leaf
 * alone, so a folder row never reached a terminal state and the queue-level banner
 * never appeared once the queue had been created. The quota classification is the same
 * story one step further: the commit that stops the queue arrives through updateItem, so
 * a banner built from the queue created earlier kept offering "free up space" on a cloud
 * portal, where the CTA is "buy a plan" (AC-030 / AC-031). All of it is read off the same
 * {@see QueueDto} the full-queue paths return, so the aggregation rule stays single.
 *
 * Cost: nothing at all for a mutation that moved no state - a heartbeat, a rejected
 * transition or an idempotent no-op never gets here - and for the rest it depends on
 * whether the change has a folder above it:
 * - it has none (a flat queue, and every queue starts flat): the folderItems half is empty
 *   by construction, and the queue-level half is folded out of the distinct
 *   (type, status, error code) combinations the queue occupies instead of its elements -
 *   ONE grouped read for the whole batch of flat queues
 *   ({@see DockedUploadItemRepository::getQueueLevelSignals()}). Both halves of that are
 *   the point: no element of the queue is read, AND the price does not multiply by the
 *   number of queues, which nothing caps.
 * - it has one: the queue is read once, narrowed to the columns the projection consumes
 *   ({@see DockedUploadItemRepository::getByQueueForProjection()}), and the whole roll-up
 *   runs in memory - so a folder of hundreds of files still costs one query per committed
 *   file, not one per ancestor. Of the rows read, only the ancestors of the changed
 *   elements are turned into DTOs: a folder DTO rolls its aggregate up by walking its own
 *   sub-tree, so building the ones that are discarded right after would cost O(N x depth)
 *   on every single call. The queue-level verdicts are still folded over every row.
 *   Several queues share one narrowed read, and the flat ones among them share the grouped
 *   signal read ({@see self::projectMany()}).
 * Either way the queue is capped at
 * {@see \Bitrix\Disk\Controller\DockedUploader\Queue::MAX_QUEUE_ITEMS} rows.
 *
 * @phpstan-type ProjectionShape array{
 *   folderItems: array<int, array>,
 *   queueDisplayStatus: string,
 *   queueActions: string[],
 *   queueQuota: array{quotaScenario: string|null, infoHelperCode: string|null},
 * }
 */
final class QueueChangeProjector
{
	public function __construct(
		private readonly DockedUploadItemRepository $itemRepository,
		private readonly DisplayStatusResolver $resolver,
	)
	{
	}

	/**
	 * @param int[] $changedItemIds the elements whose state has just been written
	 * @return ProjectionShape folder-item DTO arrays for every ancestor of the changed
	 *   elements (deepest folder and every level above it), plus the recomputed queue
	 *   actions and the queue-level quota classification
	 */
	public function project(DockedUploadQueue $queue, int $userId, array $changedItemIds): array
	{
		$queueId = (int)$queue->getId();
		if (!$this->itemRepository->hasParentedItem($queueId, $userId, $changedItemIds))
		{
			return $this->buildQueueLevel(
				$queue,
				$this->itemRepository->getQueueLevelSignals([$queueId], $userId)[$queueId] ?? [],
			);
		}

		return $this->build(
			$queue,
			$this->itemRepository->getByQueueForProjection($queueId, $userId),
			$changedItemIds,
		);
	}

	/**
	 * Same projection for several queues at once - retry scope:failed spans them - and the
	 * same two steps {@see self::project()} takes, asked once for the whole batch: ONE
	 * grouped probe tells which of the queues even have an ancestor row to rebuild, and only
	 * those are read (in one narrowed read, not one per queue). The flat ones are answered
	 * together too, by one grouped signal read that reads no row of them.
	 *
	 * The batch is what forces both to be grouped. Nothing caps the number of queues a user
	 * holds - the ceilings of {@see \Bitrix\Disk\Controller\DockedUploader\Queue} count
	 * ELEMENTS - so a per-queue answer here is a per-queue answer multiplied by a number the
	 * server does not control: a retry scope:failed over 3000 single-element queues used to
	 * cost 12 006 statements in one POST, where the grouped form costs a handful.
	 *
	 * @param array<int, DockedUploadQueue> $queues keyed by queue id
	 * @param int[] $changedItemIds across all the queues
	 * @return array<int, ProjectionShape> keyed by queue id
	 */
	public function projectMany(array $queues, int $userId, array $changedItemIds): array
	{
		if ($queues === [])
		{
			return [];
		}

		$foldered = $this->itemRepository->findQueuesWithParentedItems(
			array_keys($queues),
			$userId,
			$changedItemIds,
		);

		$itemsByQueue = [];
		if ($foldered !== [])
		{
			foreach ($this->itemRepository->getByQueuesForProjection(array_keys($foldered), $userId) as $item)
			{
				$queueId = (int)$item->getQueueId();
				$itemsByQueue[$queueId] ??= new DockedUploadItemCollection();
				$itemsByQueue[$queueId]->add($item);
			}
		}

		$flatQueueIds = array_values(array_diff(array_keys($queues), array_keys($foldered)));
		$signalsByQueue = $this->itemRepository->getQueueLevelSignals($flatQueueIds, $userId);

		$projections = [];
		foreach ($queues as $queueId => $queue)
		{
			$projections[$queueId] = isset($foldered[$queueId])
				? $this->build(
					$queue,
					$itemsByQueue[$queueId] ?? new DockedUploadItemCollection(),
					$changedItemIds,
				)
				: $this->buildQueueLevel($queue, $signalsByQueue[$queueId] ?? [])
			;
		}

		return $projections;
	}

	/**
	 * Projection of a change whose elements hang under no folder-item: folderItems is
	 * empty by construction, so no row of the queue is read. The queue-level half is folded
	 * out of the distinct (type, status, error code) combinations the queue occupies, which
	 * the caller has already taken for the whole batch
	 * ({@see DockedUploadItemRepository::getQueueLevelSignals()}) - a flat queue of hundreds
	 * of files is exactly where reading (and rolling up) every row of it buys nothing.
	 *
	 * The four membership rules below are the ones {@see QueueDto::fromEntities()} folds over
	 * whole elements, applied to the collapsed combinations instead: same three display
	 * signals through the same {@see DisplayStatusResolver}, same quota pair through
	 * {@see QueueDto::buildQuotaPayload()}. Which is why they are spelled here and not in
	 * SQL - the fold and the projection must not be able to disagree.
	 *
	 * A combination whose stored status is outside {@see ItemStatus} reads as null and is
	 * treated exactly as the existence probes this replaces treated it: it belongs to no
	 * status set, and it still counts as work left on a file, since "not committed and not
	 * canceled" is what the terminal test asks.
	 *
	 * @param list<array{itemType: ItemType|null, status: ItemStatus|null, errorCode: string|null}> $signals
	 * @return ProjectionShape
	 */
	private function buildQueueLevel(DockedUploadQueue $queue, array $signals): array
	{
		$queueStatus = $queue->getStatus() ?? QueueStatus::Active;

		$hasActiveTransfer = false;
		$hasActionableFile = false;
		$hasItemAwaitingDecision = false;
		/** @var array<int, string|null> $failedErrorCodes */
		$failedErrorCodes = [];

		foreach ($signals as $signal)
		{
			$status = $signal['status'];
			// Only leaves carry work and only leaves wait on a decision: a folder-item
			// mirrors the verdict of its sub-tree ({@see QueueDto::fromEntities()}). The
			// transfer flag and the failure codes count every element, folder rows included.
			$isFile = $signal['itemType'] === ItemType::File;

			if ($status?->isActiveTransfer())
			{
				$hasActiveTransfer = true;
			}
			if ($status === ItemStatus::Failed)
			{
				$failedErrorCodes[] = $signal['errorCode'];
			}
			if ($isFile && $status !== ItemStatus::Committed && $status !== ItemStatus::Canceled)
			{
				$hasActionableFile = true;
			}
			if ($isFile && $status !== null && $this->resolver->awaitsDecision($status))
			{
				$hasItemAwaitingDecision = true;
			}
		}

		return [
			'folderItems' => [],
			'queueDisplayStatus' => $this->resolver->resolveQueueDisplayStatus(
				$queueStatus,
				$hasActiveTransfer,
				$hasItemAwaitingDecision,
			),
			'queueActions' => $this->resolver->resolveQueueActions(
				$queueStatus,
				$hasActiveTransfer,
				$this->hasRetriableFailed($failedErrorCodes),
				$hasActionableFile,
			),
			'queueQuota' => QueueDto::buildQuotaPayload($failedErrorCodes, $this->resolver),
		];
	}

	/**
	 * @param array<int, string|null> $failedErrorCodes
	 */
	private function hasRetriableFailed(array $failedErrorCodes): bool
	{
		foreach ($failedErrorCodes as $errorCode)
		{
			if ($this->resolver->isRetriable($errorCode))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param int[] $changedItemIds
	 * @return ProjectionShape
	 */
	private function build(
		DockedUploadQueue $queue,
		DockedUploadItemCollection $items,
		array $changedItemIds,
	): array
	{
		// The ancestors are picked BEFORE the DTOs are built, not after: every folder DTO
		// walks its own sub-tree to roll the aggregate up, so building the ones that are
		// about to be discarded costs O(N x depth) per call and answers nothing.
		$queueDto = QueueDto::fromEntities(
			$queue,
			$items,
			$this->resolver,
			onlyItemIds: $this->collectAncestorIds($items, $changedItemIds),
		);

		return [
			'folderItems' => array_map(
				static fn(ItemDto $itemDto): array => $itemDto->toArray(),
				$queueDto->items,
			),
			'queueDisplayStatus' => $queueDto->displayStatus,
			'queueActions' => $queueDto->availableActions,
			'queueQuota' => $queueDto->quotaPayload(),
		];
	}

	/**
	 * Walks PARENT_ITEM_ID upwards from every changed element. Already-collected
	 * ancestors stop the walk: their own chain has been taken, which also makes a
	 * cyclic link terminate.
	 *
	 * @param iterable<DockedUploadItem> $items
	 * @param int[] $changedItemIds
	 * @return array<int, true>
	 */
	private function collectAncestorIds(iterable $items, array $changedItemIds): array
	{
		$parentById = [];
		foreach ($items as $item)
		{
			$parentById[(int)$item->getId()] = $item->getParentItemId();
		}

		$ancestorIds = [];
		foreach ($changedItemIds as $changedItemId)
		{
			$parentId = $parentById[(int)$changedItemId] ?? null;
			while ($parentId !== null && $parentId > 0 && !isset($ancestorIds[$parentId]))
			{
				$ancestorIds[$parentId] = true;
				$parentId = $parentById[$parentId] ?? null;
			}
		}

		return $ancestorIds;
	}
}
