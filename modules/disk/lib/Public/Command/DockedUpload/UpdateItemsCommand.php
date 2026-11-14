<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\DockedUpload;

use Bitrix\Disk\Internal\Dto\DockedUpload\ItemDto;
use Bitrix\Disk\Internal\Enum\DockedUpload\QueueStatus;
use Bitrix\Disk\Internal\Repository\DockedUpload\DockedUploadItemRepository;
use Bitrix\Disk\Internal\Repository\Interface\DockedUploadQueueRepositoryInterface;
use Bitrix\Disk\Internal\Service\DockedUpload\DisplayStatusResolver;
use Bitrix\Disk\Internal\Service\DockedUpload\FileCommitService;
use Bitrix\Disk\Internal\Service\DockedUpload\ItemMutation;
use Bitrix\Disk\Internal\Service\DockedUpload\ItemMutationApplier;
use Bitrix\Disk\Internal\Service\DockedUpload\OwnershipService;
use Bitrix\Disk\Internal\Service\DockedUpload\QueueChangeProjector;
use Bitrix\Disk\Internal\Service\DockedUpload\QueueEventNotifier;
use Bitrix\Disk\Internal\Service\DockedUpload\TempPresenceProbe;
use Bitrix\Disk\Internal\Service\DockedUpload\UnexpectedErrorReporter;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

/**
 * Batch form of updateItem (API-01 updateItems, CONTRACT-API-06): applies the
 * rows sequentially, in request order, each one exactly as the single command
 * would ({@see ItemMutationApplier}) - same guards, same CAS, same commit
 * trigger - and then projects and pull-emits ONCE per touched queue instead of
 * once per committed row. That is the whole point of the batch: a folder
 * upload spends fewer requests, not different semantics.
 *
 * There is NO enclosing transaction: each row's commit path stays three
 * independent durable units (the same rule as the single command). An
 * unexpected exception of a row becomes that row's error
 * ({@see UnexpectedErrorReporter}), the neighbouring rows continue. A lost CAS
 * or a rejected guarded transition is NOT a row error - the row answers with
 * the authoritative item state after the re-read, as the single endpoint does.
 *
 * The BizProc parameter set is part of the ROW ({@see ItemMutation::$bizProcParameters}),
 * so it reaches the commit of that row and no other; the batch has no set of its own,
 * and the required-parameter gate is taken per row against each element's actual
 * (storage, scenario) pair - being batched is no reason to check a set once (P8.T1).
 *
 * ONE volley is taken as a set rather than row by row: the keepalive, where every row
 * asks for nothing but a heartbeat ({@see self::isKeepaliveOnlyBatch()}). It changes no
 * status, moves no queue and projects nothing, yet the per-row way spends five statements
 * per row on it - four reads and a CAS - so a queue at its ceiling beats with thousands of
 * statements every half minute (A5). {@see self::keepaliveResults()} answers it with one
 * guarded UPDATE, one row read and one queue read, and its answer is the per-row answer:
 * same results[] in request order, same empty maps, no projection and no pull. A batch
 * with anything else in it - a status, a token, a release, a reported failure - is not
 * that volley and goes the per-row way untouched, mixed batches included.
 *
 * The per-row way is entered through one scoped read of the ids
 * ({@see DockedUploadItemRepository::getOwnedIds()}): the rows the caller does not own are
 * answered right there with the error the applier would give them anyway, so a batch of
 * made-up or foreign ids is one statement rather than one or two per row. It is a shortcut,
 * not a gate - the rows that do get acted upon are loaded and isolated by the applier as
 * before.
 */
class UpdateItemsCommand extends AbstractCommand
{
	/**
	 * @param ItemMutation[] $mutations parsed rows, in request order
	 */
	public function __construct(
		public readonly int $userId,
		public readonly array $mutations,
		private readonly ?FileCommitService $fileCommitService = null,
		private readonly ?DockedUploadItemRepository $itemRepository = null,
		private readonly ?DockedUploadQueueRepositoryInterface $queueRepository = null,
		private readonly ?DisplayStatusResolver $resolver = null,
		private readonly ?QueueEventNotifier $eventNotifier = null,
		private readonly ?OwnershipService $ownershipService = null,
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

		$keepaliveResults = $this->applyKeepaliveBatch($itemRepository, $queueRepository, $resolver);
		if ($keepaliveResults !== null)
		{
			$result->setData([
				'results' => $keepaliveResults,
				'folderItems' => [],
				'queueActions' => [],
				'queueQuota' => [],
				'queueStatuses' => [],
			]);

			return $result;
		}

		// ONE scoped read resolves the whole batch before the per-row way starts, so a
		// volley of made-up or foreign ids costs a statement rather than one or two per row.
		// It can only REMOVE work: a row that disappears between this read and its turn is
		// still stopped by the applier's own reads. AFTER the keepalive branch, which
		// answers its own rows at its own fixed price.
		$ownItemIds = $itemRepository->getOwnedIds(
			array_map(static fn(ItemMutation $mutation): int => $mutation->itemId, $this->mutations),
			$this->userId,
		);

		// The rows of a volley may name their queues in any order, so the commit service is
		// told that all of them are ONE run: its per-queue memo of the BizProc gate would
		// otherwise close on every row of an interleaved batch and re-read the workflow
		// templates per file ({@see FileCommitService::openCommitRun()}). Resolved here
		// rather than left to the applier, because the run has to be opened on the very
		// instance the rows will commit through.
		$commitService = $this->fileCommitService
			?? ServiceLocator::getInstance()->get('disk.dockedUpload.fileCommitService');

		$applier = new ItemMutationApplier(
			$itemRepository,
			$queueRepository,
			$resolver,
			$commitService,
			$this->ownershipService,
			$this->resolveReportedTempPresence(),
		);

		$results = [];
		// Per touched queue: the freshest re-read queue entity, the DTOs of the rows
		// that changed state (the pull's changedItems) and their ids (the projection
		// rebuilds the ancestors of exactly these).
		$changedByQueue = [];
		$commitService->openCommitRun();
		try
		{
			foreach ($this->mutations as $mutation)
			{
				if (!isset($ownItemIds[$mutation->itemId]))
				{
					$results[] = self::rowError($mutation->itemId, self::itemNotFoundError());

					continue;
				}

				try
				{
					$outcome = $applier->apply($this->userId, $mutation);
				}
				catch (Throwable $exception)
				{
					$results[] = self::rowError(
						$mutation->itemId,
						UnexpectedErrorReporter::report($exception, 'update items row'),
					);

					continue;
				}

				if ($outcome->error !== null)
				{
					$results[] = self::rowError($mutation->itemId, $outcome->error);

					continue;
				}

				// The row DTO is built at processing time: a later commit of the same batch
				// may move the queue further, which is exactly what N sequential single
				// requests would produce.
				$results[] = ['itemId' => (string)$mutation->itemId, 'item' => $outcome->itemDto];

				if (!$outcome->stateChanged)
				{
					continue;
				}

				$queueId = (int)$outcome->queue->getId();
				$changedByQueue[$queueId]['queue'] = $outcome->queue;
				$changedByQueue[$queueId]['itemDtos'][] = $outcome->itemDto;
				$changedByQueue[$queueId]['itemIds'][] = (int)$outcome->item->getId();
			}
		}
		finally
		{
			$commitService->closeCommitRun();
		}

		// ONE projection pass and one pull per touched queue for the whole batch -
		// against one of each per committed row under per-item addressing. An
		// untouched queue is absent from the maps (same key-presence rule as the
		// single answer), and a batch that changed nothing projects nothing.
		$folderItems = [];
		$queueActions = [];
		$queueQuota = [];
		$queueStatuses = [];
		if ($changedByQueue !== [])
		{
			$projections = (new QueueChangeProjector($itemRepository, $resolver))->projectMany(
				array_map(static fn(array $entry) => $entry['queue'], $changedByQueue),
				$this->userId,
				array_merge(...array_column($changedByQueue, 'itemIds')),
			);

			$notifier = $this->notifier();
			foreach ($changedByQueue as $queueId => $entry)
			{
				$projection = $projections[$queueId] ?? ['folderItems' => [], 'queueActions' => []];
				$folderItems = array_merge($folderItems, $projection['folderItems']);
				$queueActions[(string)$queueId] = $projection['queueActions'];
				if (isset($projection['queueQuota']))
				{
					$queueQuota[(string)$queueId] = $projection['queueQuota'];
				}

				$queueStatus = $entry['queue']->getStatus() ?? QueueStatus::Active;
				// The answer carries the same pair as the pull below, keyed like the rest of
				// the tail: a batch spans queues, and the sending tab must not depend on the
				// best-effort event to learn what its own rows did to each of them.
				$queueStatuses[(string)$queueId] = [
					'status' => $queueStatus->value,
					'displayStatus' => $projection['queueDisplayStatus'] ?? null,
				];

				$notifier->emit(
					$this->userId,
					(string)$queueId,
					$queueStatus->value,
					array_merge($entry['itemDtos'], $projection['folderItems']),
					$projection['queueActions'],
					$projection['queueQuota'] ?? null,
					$projection['queueDisplayStatus'] ?? null,
				);
			}
		}

		$result->setData([
			'results' => $results,
			'folderItems' => $folderItems,
			'queueActions' => $queueActions,
			'queueQuota' => $queueQuota,
			'queueStatuses' => $queueStatuses,
		]);

		return $result;
	}

	/**
	 * The keepalive volley taken as one set, or nothing when this batch is not one.
	 *
	 * A failure of the set path is not a row's failure - it holds no row to blame - so it
	 * is logged and the per-row loop redoes the volley: repeating the heartbeat CAS costs
	 * a row nothing and every row keeps answering for itself, as it did before A5.
	 *
	 * @return array<int, array>|null results in request order, or null to take the per-row way
	 */
	private function applyKeepaliveBatch(
		DockedUploadItemRepository $itemRepository,
		DockedUploadQueueRepositoryInterface $queueRepository,
		DisplayStatusResolver $resolver,
	): ?array
	{
		if (!$this->isKeepaliveOnlyBatch())
		{
			return null;
		}

		try
		{
			return $this->keepaliveResults($itemRepository, $queueRepository, $resolver);
		}
		catch (Throwable $exception)
		{
			UnexpectedErrorReporter::report($exception, 'update items keepalive batch');

			return null;
		}
	}

	/**
	 * The same idea as the scoped id read above, for the bytes a hand-back reports: every
	 * token the release rows of this batch carry is looked up in ONE chunked probe instead of
	 * one transport call per row, and the verdicts ride into the applier.
	 *
	 * An unload volley releases every row the closing tab held, so the per-row question is
	 * exactly the shape that grows with the size of the packet. A token the probe cannot
	 * speak for - malformed, or past its per-request ceiling - is simply absent from the map,
	 * and its row asks for itself as it always did.
	 *
	 * @return array<string, bool>
	 */
	private function resolveReportedTempPresence(): array
	{
		$tokens = [];
		foreach ($this->mutations as $mutation)
		{
			if (
				$mutation instanceof ItemMutation
				&& $mutation->releaseOwnership
				&& $mutation->tempToken !== null
				&& $mutation->tempToken !== ''
			)
			{
				$tokens[] = $mutation->tempToken;
			}
		}

		if ($tokens === [])
		{
			return [];
		}

		[$presentByToken] = (new TempPresenceProbe())->resolve($tokens, $this->userId);

		return $presentByToken;
	}

	/**
	 * Whether every row of the batch asks for a heartbeat and nothing else - the only shape
	 * the set path may answer for. {@see ItemMutation::isKeepaliveBatch()}
	 */
	private function isKeepaliveOnlyBatch(): bool
	{
		return ItemMutation::isKeepaliveBatch($this->mutations);
	}

	/**
	 * @return array<int, array> results in request order
	 */
	private function keepaliveResults(
		DockedUploadItemRepository $itemRepository,
		DockedUploadQueueRepositoryInterface $queueRepository,
		DisplayStatusResolver $resolver,
	): array
	{
		$ownership = $this->ownershipService
			?? ServiceLocator::getInstance()->get('disk.dockedUpload.ownershipService');

		$itemIdsByClient = [];
		foreach ($this->mutations as $mutation)
		{
			$itemIdsByClient[$mutation->ownerClientId][] = $mutation->itemId;
		}

		// One statement per tab that beats. A volley comes from one tab, so this is one
		// statement - the loop only keeps a hand-built multi-tab batch behaving per tab.
		foreach ($itemIdsByClient as $clientId => $itemIds)
		{
			$ownership->heartbeatMany($itemIds, $this->userId, (string)$clientId);
		}

		// ONE authoritative read for the whole volley. It also answers who owns each row,
		// which is what the per-row path pays its read-back for when the CAS reports no
		// changed row (an unchanged timestamp and a refused guard look the same there).
		$items = [];
		foreach ($itemRepository->getByIds(array_merge(...array_values($itemIdsByClient)), $this->userId) as $item)
		{
			$items[(int)$item->getId()] = $item;
		}

		// ...and ONE queue read: a keepalive never moves a queue, so the queue is read for
		// the DTO status alone and needs no re-read after the CAS.
		$queueIds = [];
		foreach ($items as $item)
		{
			$queueIds[(int)$item->getQueueId()] = true;
		}

		$queueStatuses = [];
		foreach ($queueRepository->getByIds(array_keys($queueIds), $this->userId) as $queue)
		{
			$queueStatuses[(int)$queue->getId()] = $queue->getStatus() ?? QueueStatus::Active;
		}

		$results = [];
		foreach ($this->mutations as $mutation)
		{
			$item = $items[$mutation->itemId] ?? null;
			$queueStatus = $item === null ? null : ($queueStatuses[(int)$item->getQueueId()] ?? null);
			if ($queueStatus === null)
			{
				// Isolation and existence at once: the read answers within the caller's own
				// queues only, so an unknown row, a foreign one and one whose queue is gone
				// look alike here - the single error the per-row path gives all three.
				$results[] = self::rowError($mutation->itemId, self::itemNotFoundError());

				continue;
			}

			// API-01 addresses file elements only, on this branch as on the per-row one
			// ({@see ItemMutationApplier::apply()}): a structural folder row is refused
			// row-wise, before the DTO fromFileItem() would build for it drops its
			// aggregate fields. The heartbeat itself never reached the row - the CAS
			// carries the same type predicate ({@see OwnershipService::heartbeatMany()}) -
			// so the refusal only reports a write that did not happen.
			if ($item->isFolder())
			{
				$results[] = self::rowError($mutation->itemId, self::itemNotFileError());

				continue;
			}

			$results[] = [
				'itemId' => (string)$mutation->itemId,
				'item' => ItemDto::fromFileItem($item, $queueStatus, $resolver)->toArray(),
			];
		}

		return $results;
	}

	/**
	 * The one answer a row gets when the caller may not act on it: unknown, foreign, or
	 * hanging off a queue that is gone. The three are deliberately indistinguishable -
	 * telling them apart would say whose id exists ({@see ItemMutationApplier::apply()}).
	 */
	private static function itemNotFoundError(): Error
	{
		return new Error('Item not found', 'DISK_DOCKED_ITEM_NOT_FOUND');
	}

	/**
	 * The answer a structural folder row gets, worded exactly as the per-row way words it
	 * ({@see ItemMutationApplier::apply()}): the two branches of one endpoint may not
	 * differ in what the client reads.
	 */
	private static function itemNotFileError(): Error
	{
		return new Error('Item is not a file element', 'DISK_DOCKED_ITEM_NOT_FILE');
	}

	/**
	 * @return array{itemId: string, error: array{code: string, message: string}}
	 */
	private static function rowError(int $itemId, Error $error): array
	{
		return [
			'itemId' => (string)$itemId,
			'error' => [
				'code' => (string)$error->getCode(),
				'message' => $error->getMessage(),
			],
		];
	}

	private function notifier(): QueueEventNotifier
	{
		return $this->eventNotifier
			?? ServiceLocator::getInstance()->get('disk.dockedUpload.queueEventNotifier');
	}
}
