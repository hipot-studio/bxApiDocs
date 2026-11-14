<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\DockedUpload;

use Bitrix\Disk\Internal\Dto\DockedUpload\ItemDto;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadQueue;
use Bitrix\Disk\Internal\Enum\DockedUpload\ConflictPolicy;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;
use Bitrix\Disk\Internal\Enum\DockedUpload\QueueStatus;
use Bitrix\Disk\Internal\Repository\DockedUpload\DockedUploadItemRepository;
use Bitrix\Disk\Internal\Repository\Interface\DockedUploadQueueRepositoryInterface;
use Bitrix\Disk\Internal\Service\DockedUpload\DisplayStatusResolver;
use Bitrix\Disk\Internal\Service\DockedUpload\FolderTreeBuilder;
use Bitrix\Disk\Internal\Service\DockedUpload\FolderTreePathAnalyzer;
use Bitrix\Disk\Internal\Service\DockedUpload\QueueChangeProjector;
use Bitrix\Disk\Internal\Service\DockedUpload\QueueEventNotifier;
use Bitrix\Disk\Internal\Service\DockedUpload\UnexpectedErrorReporter;
use Bitrix\Disk\Internals\Steppers\DockedFolderTreeStepper;
use Bitrix\Main\Application;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

/**
 * Batch-creates the folder tree for a docked folder upload and binds the queued
 * file items to it (API-04).
 *
 * Small batches are built synchronously; large ones are handed to
 * {@see DockedFolderTreeStepper} and answered with a stepperToken. A failed
 * branch never rolls back folders already created, and empty sub-folders are
 * never auto-deleted (invariant).
 *
 * A folder upload that carries no file has nothing left to do once its tree
 * stands, so the sync branch also drains the queue and reports the resulting
 * queueStatus (AC-077).
 *
 * A branch that could not be created is a terminal verdict the SERVER produces
 * (docked_folder_tree_failed), so this command delivers it itself instead of leaving
 * it to be picked up by the projection riding a neighbouring element's updateItem:
 * when every file of the folder landed in the broken branch there is no such
 * neighbour, and the folder row would sit at "queued" until a pull or a reload. The
 * failed leaves, the folder rows above them and the recomputed queue actions/quota
 * travel with the answer AND with the pull event, the same shape updateItem uses.
 */
class CreateFolderTreeCommand extends AbstractCommand
{
	/**
	 * @param string[] $relativePaths
	 */
	public function __construct(
		public readonly int $userId,
		public readonly int $queueId,
		public readonly int $rootFolderId,
		public readonly int $folderItemId,
		public readonly array $relativePaths,
		public readonly ?ConflictPolicy $conflictPolicy = null,
		private readonly ?FolderTreeBuilder $folderTreeBuilder = null,
		private readonly ?DockedUploadItemRepository $itemRepository = null,
		private readonly ?DockedUploadQueueRepositoryInterface $queueRepository = null,
		private readonly ?QueueEventNotifier $eventNotifier = null,
		private readonly ?DisplayStatusResolver $resolver = null,
	)
	{
	}

	protected function execute(): Result
	{
		$result = new Result();

		// Collapse duplicate paths once, up front - the single point every downstream
		// consumer (node-count sizing, sync buildTree and the async stepper hand-off)
		// reads. The number of distinct paths is always <= the unique-node count, so
		// this caps branch allocation at the ceiling already enforced on unique nodes
		// and stops identical paths from amplifying the build (I5).
		$relativePaths = array_values(array_unique($this->relativePaths));

		$itemRepository = $this->itemRepository ?? new DockedUploadItemRepository();
		$queueRepository = $this->queueRepository
			?? ServiceLocator::getInstance()->get(DockedUploadQueueRepositoryInterface::class);
		$builder = $this->folderTreeBuilder
			?? ServiceLocator::getInstance()->get('disk.dockedUpload.folderTreeBuilder');

		$queue = $queueRepository->getById($this->queueId);
		if ($queue === null || (int)$queue->getUserId() !== $this->userId)
		{
			return $result->addError(new Error('Queue not found', 'DISK_DOCKED_QUEUE_NOT_FOUND'));
		}

		// The type is asked AFTER the ownership and the queue membership, so a foreign id
		// stays indistinguishable whatever it points at. The conflict policy and the
		// inherited storage this command stamps are attributes of a folder row - the
		// enqueue side already holds that invariant
		// ({@see CreateQueueCommand::buildItem()} writes CONFLICT_POLICY only for a
		// folder), and a file row given here would be stamped with a policy nobody reads
		// and a storage its own destination never agreed to.
		$folderItem = $itemRepository->getById($this->folderItemId);
		if ($folderItem === null || (int)$folderItem->getQueueId() !== $this->queueId || !$folderItem->isFolder())
		{
			return $result->addError(new Error('Folder item not found', 'DISK_DOCKED_ITEM_NOT_FOUND'));
		}

		// The upload may have been called off between two chunks of the same folder: the
		// client cuts a big tree into several requests, so a request arriving after the
		// cancel is the norm here, not a race. Cancel is the pair "folder-item terminal"
		// (both the queue-wide cancel and the folder's own one mark the structural row) and
		// "queue dismissed" - NEVER "queue no longer restorable": a queue reaches completed
		// through the AC-077 drain, which a neighbouring chunk of this very tree performs,
		// and a queue stopped by quota must still finish its tree for the restore to resume
		// into. An idempotent no-op rather than an error, the way
		// {@see ResolveConflictCommand::replace()} answers a queue it may no longer commit
		// to: a stale tab must not be told off about an upload it no longer shows.
		$queueStatus = $queue->getStatus() ?? QueueStatus::Active;
		if ($queueStatus === QueueStatus::Dismissed || self::isTerminal($folderItem->getStatus()))
		{
			return $result->setData(self::idleAnswer($queueStatus));
		}

		$root = $builder->resolveWritableRoot($this->rootFolderId, $this->userId);
		if ($root === null)
		{
			return $result->addError(new Error('Access denied to the root folder', 'DISK_DOCKED_ROOT_DENIED'));
		}

		// Persist the folder-level conflict policy (AC-071/072) on the folder-item.
		$conflictPolicy = $this->conflictPolicy ?? ConflictPolicy::default();
		$folderItem->setConflictPolicy($conflictPolicy);

		// The folder-item anchors the sub-tree but is structural: the create request may name
		// no target for it at all, leaving it without a storage. The root just resolved is
		// that storage, and it is already loaded - no extra query to inherit it from.
		$rootStorageId = self::positiveOrNull((int)$root->getStorageId());
		if ($folderItem->getStorageId() === null)
		{
			$folderItem->setStorageId($rootStorageId);
		}

		// Size the request by the number of distinct tree nodes it materialises, not
		// the number of paths: a handful of deep paths can still create many folders.
		if (FolderTreePathAnalyzer::countUniqueNodes($relativePaths) > DockedFolderTreeStepper::PORTION)
		{
			// Async: the stepper builds the tree and binds the items later, in its
			// own portioned steps - do not wrap that hand-off in a transaction (R5).
			try
			{
				$stamped = $itemRepository->stampFolderPolicy(
					$this->folderItemId,
					$this->userId,
					$conflictPolicy,
					$rootStorageId,
				);
				if (!$stamped)
				{
					// Lost the guard (canceled or committed since the read above): the row is
					// terminal, so nothing may be handed to the stepper either.
					return $result->setData(self::idleAnswer($queueRepository->getById($this->queueId)?->getStatus()));
				}

				$token = DockedFolderTreeStepper::enqueue([
					'userId' => $this->userId,
					'rootFolderId' => (int)$root->getId(),
					'folderItemId' => $this->folderItemId,
					'relativePaths' => $relativePaths,
				]);
			}
			catch (Throwable $exception)
			{
				return $result->addError(UnexpectedErrorReporter::report($exception, 'create folder tree'));
			}

			// The verdict keys travel empty rather than absent: nothing is built yet, so
			// there is nothing to report, and a uniform answer shape spares the client a
			// branch. The async verdict arrives later, through the pull event alone.
			$result->setData([
				'stepperToken' => $token,
				'queueStatus' => $queueStatus->value,
				'queueDisplayStatus' => null,
				'failedItems' => [],
				'folderItems' => [],
				'queueActions' => [],
				'queueQuota' => [],
			]);

			return $result;
		}

		// Sync: the whole tree is built in-line across several ORM writes - wrap it
		// in a transaction so an unexpected failure leaves no partial mutation (R5).
		// A branch that legitimately cannot be created does not throw (its failure
		// is isolated in buildTree), so it never rolls back the healthy siblings.
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$stamped = $itemRepository->stampFolderPolicy(
				$this->folderItemId,
				$this->userId,
				$conflictPolicy,
				$rootStorageId,
			);
			if (!$stamped)
			{
				$connection->rollbackTransaction();

				return $result->setData(self::idleAnswer($queueRepository->getById($this->queueId)?->getStatus()));
			}

			$tree = $builder->buildTree($root, $relativePaths, $this->userId);
			$bound = $builder->bindFileItems(
				$this->folderItemId,
				$this->userId,
				(int)$root->getId(),
				$tree['folderMap'],
				$tree['failedPaths'],
				$rootStorageId,
			);

			// AC-077: building the tree is the last work a folder upload with no file to
			// transfer ever has - no commit will follow to drain its queue, so it would
			// stay active forever (the widget hanging at 0%). The transition is the same
			// guarded one the commit path uses: it re-derives the drain from the file
			// leaves, so a folder that does carry files is left untouched here.
			$completed = $queueRepository->markCompletedIfDrained($this->queueId);

			$connection->commitTransaction();
		}
		catch (Throwable $exception)
		{
			$connection->rollbackTransaction();

			return $result->addError(UnexpectedErrorReporter::report($exception, 'create folder tree'));
		}

		if ($completed)
		{
			// Set before the projection below: the queue DTO it reads composes every
			// display status and the action list against this very status.
			$queueStatus = QueueStatus::Completed;
			$queue->setStatus(QueueStatus::Completed);
		}

		$delivery = $this->deliverVerdict($queue, $bound['failedItemIds'], $completed, $itemRepository);

		$result->setData([
			'createdFolders' => $tree['createdFolders'],
			'fileItemBindings' => $bound['bindings'],
			'queueStatus' => $queueStatus->value,
			// The display projection of that status, beside the stored one and never instead
			// of it: a build whose branch failed leaves the queue stored active while it is
			// DISPLAYED as requiring a decision, and the client may not compose that itself
			// (DTO-01). It is null when this build recomputed no verdict.
			'queueDisplayStatus' => $delivery['queueDisplayStatus'],
			'failedItems' => $delivery['failedItems'],
			'folderItems' => $delivery['folderItems'],
			'queueActions' => $delivery['queueActions'],
			'queueQuota' => $delivery['queueQuota'],
		]);

		return $result;
	}

	/**
	 * Ships the server verdict of this build - the failed leaves, the folder rows their
	 * failure moved, the recomputed queue actions and the quota classification - to the
	 * caller and, through the pull event, to the neighbouring tabs.
	 *
	 * The queue is read (one batched query) only when there is a verdict to ship: a
	 * failed branch, or the AC-077 drain that ends the queue. A clean build changes no
	 * status, so the happy path keeps costing nothing extra.
	 *
	 * The failed rows are RE-READ rather than taken from the builder's in-memory copies:
	 * {@see DockedUploadItemRepository::updateStatusByIds()} guards terminal rows out of
	 * the UPDATE, so an item that raced to committed/canceled kept its state in the DB
	 * while the in-memory copy was already mutated to failed.
	 *
	 * @param int[] $failedItemIds
	 * @return array{
	 *     failedItems: array<int, array>,
	 *     folderItems: array<int, array>,
	 *     queueActions: array<string, string[]>,
	 *     queueQuota: array<string, array{quotaScenario: string|null, infoHelperCode: string|null}>,
	 *     queueDisplayStatus: string|null,
	 * }
	 */
	private function deliverVerdict(
		DockedUploadQueue $queue,
		array $failedItemIds,
		bool $completed,
		DockedUploadItemRepository $itemRepository,
	): array
	{
		$empty = [
			'failedItems' => [],
			'folderItems' => [],
			'queueActions' => [],
			'queueQuota' => [],
			'queueDisplayStatus' => null,
		];
		if ($failedItemIds === [] && !$completed)
		{
			return $empty;
		}

		$resolver = $this->resolver
			?? ServiceLocator::getInstance()->get('disk.dockedUpload.displayStatusResolver');
		$queueStatus = $queue->getStatus() ?? QueueStatus::Active;

		$failedItems = [];
		foreach ($itemRepository->getByIds($failedItemIds, $this->userId) as $item)
		{
			$failedItems[] = ItemDto::fromFileItem($item, $queueStatus, $resolver)->toArray();
		}

		$projection = (new QueueChangeProjector($itemRepository, $resolver))
			->project($queue, $this->userId, $failedItemIds)
		;

		$this->notifier()->emit(
			$this->userId,
			(string)$queue->getId(),
			$queueStatus->value,
			array_merge($failedItems, $projection['folderItems']),
			$projection['queueActions'],
			$projection['queueQuota'],
			$projection['queueDisplayStatus'],
		);

		return [
			'failedItems' => $failedItems,
			// Keyed by queue so the shape matches updateItem / retry, where an untouched
			// queue is absent rather than an empty list.
			'folderItems' => $projection['folderItems'],
			'queueActions' => [(string)$queue->getId() => $projection['queueActions']],
			'queueQuota' => [(string)$queue->getId() => $projection['queueQuota']],
			// Flat, like the stored status this answer already carries: API-04 names its one
			// queue, so the pair travels unkeyed here exactly as it does in the pull event.
			'queueDisplayStatus' => $projection['queueDisplayStatus'],
		];
	}

	private function notifier(): QueueEventNotifier
	{
		return $this->eventNotifier
			?? ServiceLocator::getInstance()->get('disk.dockedUpload.queueEventNotifier');
	}

	private static function isTerminal(?ItemStatus $status): bool
	{
		return $status === ItemStatus::Committed || $status === ItemStatus::Canceled;
	}

	/**
	 * The answer of a build that did nothing - the closed upload and the lost guard alike.
	 * Every key a finished build reports travels empty rather than absent, so the caller
	 * keeps the single answer shape, and the queue status is the one the row carries now.
	 *
	 * @return array<string, mixed>
	 */
	private static function idleAnswer(?QueueStatus $queueStatus): array
	{
		return [
			'createdFolders' => [],
			'fileItemBindings' => [],
			'queueStatus' => $queueStatus?->value,
			'queueDisplayStatus' => null,
			'failedItems' => [],
			'folderItems' => [],
			'queueActions' => [],
			'queueQuota' => [],
		];
	}

	private static function positiveOrNull(int $value): ?int
	{
		return $value > 0 ? $value : null;
	}
}
