<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\DockedUpload;

use Bitrix\Disk\Internal\Dto\DockedUpload\QueueDto;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItem;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;
use Bitrix\Disk\Internal\Enum\DockedUpload\QueueStatus;
use Bitrix\Disk\Internal\Repository\DockedUpload\DockedUploadItemRepository;
use Bitrix\Disk\Internal\Repository\Interface\DockedUploadQueueRepositoryInterface;
use Bitrix\Disk\Internal\Service\DockedUpload\CommitReconciler;
use Bitrix\Disk\Internal\Service\DockedUpload\DisplayStatusResolver;
use Bitrix\Disk\Internal\Service\DockedUpload\DurableOrphanReleaser;
use Bitrix\Disk\Internal\Service\DockedUpload\QueueEventNotifier;
use Bitrix\Disk\Internal\Service\DockedUpload\QueueTargetNameResolver;
use Bitrix\Disk\Internal\Service\DockedUpload\TempPresenceProbe;
use Bitrix\Disk\Internal\Service\DockedUpload\UnexpectedErrorReporter;
use Bitrix\Main\Application;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\UI\FileUploader\Uploader;
use Throwable;

/**
 * Cancels a whole queue, a single item or a pack of items (API-01 cancel).
 *
 * Cancellation is idempotent: it frees the temp files of the non-committed items that are
 * the caller's own ({@see self::releaseTemp()}), never touches a committed DISK_OBJECT_ID,
 * and does not delete empty sub-folders created by createFolderTree (invariant).
 *
 * itemIds (CONTRACT-API-06) is the batch form of the single-item branch: every named row
 * seeds the same sub-tree walk, and their union is closed by one batch UPDATE. The pack
 * addresses ONE queue - rows of several is a refusal, not a pick - since the answer carries
 * a single re-derived queue. Ids the resolved queue does not hold are silently skipped, the
 * rule the itemIds retry follows.
 */
class CancelCommand extends AbstractCommand
{
	/**
	 * @param int[]|null $itemIds
	 */
	public function __construct(
		public readonly int $userId,
		public readonly ?int $queueId = null,
		public readonly ?int $itemId = null,
		public readonly ?array $itemIds = null,
		private readonly ?DockedUploadItemRepository $itemRepository = null,
		private readonly ?DockedUploadQueueRepositoryInterface $queueRepository = null,
		private readonly ?DisplayStatusResolver $resolver = null,
		private readonly ?Uploader $uploader = null,
		private readonly ?QueueEventNotifier $eventNotifier = null,
		private readonly ?CommitReconciler $reconciler = null,
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

		$anchorIds = $this->resolveAnchorIds();

		$queueId = $this->queueId;
		if ($queueId === null && $anchorIds !== null)
		{
			$queueIds = $this->queueIdsOfAnchors($itemRepository, $anchorIds);
			if (count($queueIds) > 1)
			{
				// A pack spanning two queues is not one cancel: the answer carries a single
				// re-derived queue, so serving one of them would close half the address and
				// report nothing about the rest. Refused outright, never "the first wins".
				return $result->addError(new Error('Ambiguous cancel target', 'DISK_DOCKED_CANCEL_TARGET_AMBIGUOUS'));
			}

			$queueId = $queueIds[0] ?? null;
		}

		$queue = $queueId !== null ? $queueRepository->getById((int)$queueId) : null;
		if ($queue === null || (int)$queue->getUserId() !== $this->userId)
		{
			return $result->addError(new Error('Queue not found', 'DISK_DOCKED_QUEUE_NOT_FOUND'));
		}

		$all = $itemRepository->getByQueue((int)$queue->getId(), $this->userId);

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			// Collect the temp tokens of the non-committed targets so they are freed
			// with a single Uploader::remove() after the transaction (PM1), and
			// resolve the non-terminal targets for a single batch status UPDATE
			// instead of one round-trip per item (PL3, up to 1000 UPDATEs previously).
			[$tokensToRelease, $targets] = $this->collectCancelable($this->collectTargets($all, $anchorIds));

			if ($targets !== [])
			{
				if ($anchorIds === null)
				{
					// Whole-queue cancel: STATUS = canceled WHERE QUEUE_ID = ? AND
					// STATUS NOT IN (terminal), one UPDATE for the whole queue.
					$itemRepository->updateStatusByQueue(
						(int)$queue->getId(),
						ItemStatus::Canceled,
						[ItemStatus::Committed, ItemStatus::Canceled],
						userId: $this->userId,
					);
				}
				else
				{
					// Item(+children) cancel: batch by the resolved id list - the union of
					// every named sub-tree; the repository still scopes the UPDATE to this
					// user's queues.
					$ids = array_map(static fn(DockedUploadItem $item): int => (int)$item->getId(), $targets);
					$itemRepository->updateStatusByIds($ids, ItemStatus::Canceled, $this->userId);
				}
			}

			// Drain the queue on the same guarded predicate the commit path uses: it
			// completes only when no file leaf is left uncommitted/uncanceled, and the
			// UPDATE runs inside this transaction so it sees the batch cancel above. A
			// folder-item is never terminal, so the old leaf-agnostic scan left a
			// fully-canceled folder tree active forever (AC-077). The method is
			// idempotent, race-safe, and its status guard skips a dismissed queue. Its
			// verdict is not carried in memory - the re-read below answers for the row.
			$queueRepository->markCompletedIfDrained((int)$queue->getId());

			$connection->commitTransaction();
		}
		catch (Throwable $exception)
		{
			$connection->rollbackTransaction();

			return $result->addError(UnexpectedErrorReporter::report($exception, 'cancel'));
		}

		// Freeing temp files is external and best-effort: keep it out of the queue
		// transaction so a storage hiccup never rolls back the cancel (durability).
		$this->releaseTemp($tokensToRelease);

		// Symmetric with the commit-path orphan cleanup and shared verbatim with the
		// widget close: a crash between the domain write (already durable) and the atomic
		// commit leaves a live disk artifact carrying the item's INTENT_TOKEN while the
		// item is still non-committed, and nothing else ever collects it. Best-effort,
		// outside the queue transaction.
		$this->orphanReleaser()->release($targets, $itemRepository, $this->userId);

		// Neither half of the pre-transaction snapshot still describes the queue: the batch
		// UPDATE skips a row a concurrent commit has already won, and the drain gate leaves
		// the QUEUE row alone whenever somebody else moved it - a commit that completed it,
		// a quota stop, a dismiss from a neighbouring tab. Both are re-read after the commit
		// - a consistent read inside the transaction would still hide those writes - so the
		// answer and the pull event carry what the database really holds. The queue status
		// is not one field among many: displayStatus, availableActions, restorable and the
		// projection of EVERY row derive from it. The row may be gone, hence the fallback.
		$all = $itemRepository->getByQueue((int)$queue->getId(), $this->userId);
		$queue = $queueRepository->getById((int)$queue->getId()) ?? $queue;

		$queueDto = QueueDto::fromEntities($queue, $all, $resolver, [], new QueueTargetNameResolver());
		$queuePayload = $queueDto->toArray();

		// Cancelling the quota culprit is one of the ways out of a quota stop, so the
		// classification travels with the event too: the answer carries the whole queue
		// DTO, the neighbouring tabs get this payload only.
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

	private function notifier(): QueueEventNotifier
	{
		return $this->eventNotifier
			?? ServiceLocator::getInstance()->get('disk.dockedUpload.queueEventNotifier');
	}

	/**
	 * The rows this cancel is addressed at: the itemIds pack, the single itemId as a pack
	 * of one, or null for the whole-queue form. Unusable values are dropped rather than
	 * coerced, so a pack of nothing but garbage addresses no row at all and is answered
	 * like an unknown id is.
	 *
	 * @return int[]|null
	 */
	private function resolveAnchorIds(): ?array
	{
		if ($this->itemIds !== null)
		{
			return array_values(array_unique(array_filter(
				array_map('intval', $this->itemIds),
				static fn(int $id): bool => $id > 0,
			)));
		}

		return $this->itemId !== null ? [$this->itemId] : null;
	}

	/**
	 * The distinct queues the anchors belong to, read once for the whole pack. The read is
	 * user-scoped, so a foreign or unknown id never comes back and needs no check of its
	 * own; the queue-ownership gate downstream still guards the one queue that does.
	 *
	 * @param int[] $anchorIds
	 * @return int[]
	 */
	private function queueIdsOfAnchors(DockedUploadItemRepository $itemRepository, array $anchorIds): array
	{
		if ($anchorIds === [])
		{
			return [];
		}

		$queueIds = [];
		foreach ($itemRepository->getByIds($anchorIds, $this->userId) as $item)
		{
			$queueIds[(int)$item->getQueueId()] = true;
		}

		return array_keys($queueIds);
	}

	/**
	 * @param iterable<DockedUploadItem> $all
	 * @param int[]|null $anchorIds
	 * @return DockedUploadItem[]
	 */
	private function collectTargets(iterable $all, ?array $anchorIds): array
	{
		$byId = [];
		$children = [];
		foreach ($all as $item)
		{
			$byId[(int)$item->getId()] = $item;
			$parentId = $item->getParentItemId();
			if ($parentId !== null)
			{
				$children[$parentId][] = $item;
			}
		}

		if ($anchorIds === null)
		{
			return array_values($byId);
		}

		// Cancelling a folder-item cancels its whole sub-tree - nested sub-folder
		// items and the files inside them - walked breadth-first over the in-memory
		// children map with a seen-set, so a deep folder -> subfolder -> file chain is
		// fully cancelled (and its temp tokens freed) without extra queries. A pack seeds
		// the very same walk with all of its anchors at once, so overlapping sub-trees are
		// still visited once; an id this queue does not hold is absent from the snapshot
		// and is skipped by construction, not by a check.
		$targets = [];
		$seen = [];
		$level = [];
		foreach ($anchorIds as $anchorId)
		{
			if (!isset($byId[$anchorId]) || isset($seen[$anchorId]))
			{
				continue;
			}

			$seen[$anchorId] = true;
			$targets[] = $byId[$anchorId];
			$level[] = $anchorId;
		}

		while ($level !== [])
		{
			$nextLevel = [];
			foreach ($level as $parentId)
			{
				foreach ($children[$parentId] ?? [] as $child)
				{
					$childId = (int)$child->getId();
					if (isset($seen[$childId]))
					{
						continue;
					}

					$seen[$childId] = true;
					$targets[] = $child;
					$nextLevel[] = $childId;
				}
			}

			$level = $nextLevel;
		}

		return $targets;
	}

	/**
	 * Filters out already-terminal targets (committed / already canceled -
	 * idempotent no-op, keep DISK_OBJECT_ID) and collects the temp tokens of the
	 * remaining ones. Does NOT touch the database: the caller flips the
	 * survivors' STATUS with a single batch UPDATE.
	 *
	 * @param DockedUploadItem[] $items
	 * @return array{0: string[], 1: DockedUploadItem[]} [$tokensToRelease, $cancelableTargets]
	 */
	private function collectCancelable(array $items): array
	{
		$tokensToRelease = [];
		$cancelable = [];
		foreach ($items as $item)
		{
			if (self::isTerminal($item->getStatus()))
			{
				continue;
			}

			$tempToken = $item->getTempToken();
			if ($tempToken !== null && $tempToken !== '')
			{
				$tokensToRelease[] = $tempToken;
			}

			$cancelable[] = $item;
		}

		return [$tokensToRelease, $cancelable];
	}

	/**
	 * Frees the temp files of the canceled items - the ones that are the caller's own.
	 *
	 * The same rule, through the same filter, as the widget close
	 * ({@see DismissCommand::releaseTemp()}): {@see Uploader::remove()} deletes a temp row by
	 * the GUID inside the token and never compares CREATED_BY, while the token on a row is
	 * whatever the client wrote there, so the cancel of a queue holding a foreign token would
	 * otherwise drop somebody else's bytes. One batched probe answers for the whole cancel;
	 * what it cannot confirm - a foreign token, a temp already gone, or the tail past its
	 * per-request ceiling - is not freed here and stays with the TTL agent.
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

	private function orphanReleaser(): DurableOrphanReleaser
	{
		return new DurableOrphanReleaser($this->reconciler ?? new CommitReconciler());
	}

	private static function isTerminal(?ItemStatus $status): bool
	{
		return $status === ItemStatus::Committed || $status === ItemStatus::Canceled;
	}
}
