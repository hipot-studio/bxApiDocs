<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\DockedUpload;

use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;
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
use Bitrix\Disk\Internal\Service\DockedUpload\UnexpectedErrorReporter;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;
use Throwable;

/**
 * Binds an item to its transport state and, on the happy path, triggers the
 * synchronous exactly-once commit (API-01 updateItem).
 *
 * Only the transition status=uploaded_to_temp with a non-empty tempToken on a queue
 * that still takes work ({@see QueueStatus::acceptsWork()}) starts
 * {@see FileCommitService::commit()}. Heartbeat (ownerClientId) and intermediate
 * uploading transitions never commit.
 *
 * A reported transportError is the one client-driven route into a terminal state,
 * and it is not an exception to "terminal statuses are produced by the server":
 * the client only names the item, while
 * {@see FileCommitService::applyTransportFailure()} re-checks the reason - the
 * access restriction first, then the quota - and decides.
 *
 * Ownership transitions ({@see $ownerClientId}) are routed through
 * {@see OwnershipService} (heartbeat-or-acquire CAS): the server is the arbiter of
 * the row (P4.T1), so a race between two surviving tabs resolves to a single
 * winner and the loser never overwrites the owner. A tab reclaims a dead owner by
 * naming it in {@see $knownStaleClientId} and hands its own back with
 * {@see $releaseOwnership}; neither route commits, and neither can take a row a
 * live tab still holds.
 *
 * The per-row logic itself lives in {@see ItemMutationApplier} (shared with the
 * batch UpdateItemsCommand); this command runs it for its one row and delivers
 * the projection and the pull event.
 *
 * The commit path (ALG-01) is deliberately NOT wrapped in a command-level
 * transaction - it consists of three independent durable units.
 */
class UpdateItemCommand extends AbstractCommand
{
	/**
	 * @param array $bizProcParameters collected workflow parameters (API-03) routed
	 *   to the durable-committed BizProc autostart (P2.T5/R3).
	 * @param string|null $knownStaleClientId the owner the caller believes is dead
	 *   (named by the client through its BroadcastChannel liveness check, P4.T1).
	 *   It only widens the CAS guard to that one owner. The client's verdict is
	 *   final - OWNER_HEARTBEAT_AT is not part of the acquire guard, so naming a
	 *   LIVE tab does take the row away from it. Liveness is not arbitrated here;
	 *   the only guard against a false death detect is the client threshold.
	 * @param bool $releaseOwnership graceful hand-back on tab close: reclassify the
	 *   item (uploaded_to_temp when the bytes are already in temp, otherwise
	 *   interrupted_needs_file) and clear the owner. It never resumes a transfer.
	 *   On the wire this is the named action `ownerAction=release` (API-01), which
	 *   {@see \Bitrix\Disk\Controller\DockedUploader\Queue::updateItemAction()} turns
	 *   into this flag by strict comparison - the untrusted value never reaches here
	 *   as a bool to be coerced.
	 * @param array|null $transportError the terminal transport failure the client
	 *   reports for this item as `['code' => string]` (API-01 additive field). The
	 *   code is the opaque one the server itself issued from
	 *   {@see \Bitrix\Disk\Controller\DockedUploader\Transport::onUploadError()}; it
	 *   only says WHICH item died and gives a reason to re-check - the verdict is
	 *   taken server-side.
	 */
	public function __construct(
		public readonly int $userId,
		public readonly int $itemId,
		public readonly ?string $tempToken = null,
		public readonly ?ItemStatus $status = null,
		public readonly ?string $ownerClientId = null,
		public readonly ?string $knownStaleClientId = null,
		public readonly bool $releaseOwnership = false,
		public readonly array $bizProcParameters = [],
		public readonly bool $bizProcParametersAreSigned = false,
		public readonly ?array $transportError = null,
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

		$applier = new ItemMutationApplier(
			$itemRepository,
			$queueRepository,
			$resolver,
			$this->fileCommitService,
			$this->ownershipService,
		);

		try
		{
			$outcome = $applier->apply($this->userId, new ItemMutation(
				itemId: $this->itemId,
				tempToken: $this->tempToken,
				status: $this->status,
				ownerClientId: $this->ownerClientId,
				knownStaleClientId: $this->knownStaleClientId,
				releaseOwnership: $this->releaseOwnership,
				bizProcParameters: $this->bizProcParameters,
				bizProcParametersAreSigned: $this->bizProcParametersAreSigned,
				transportError: $this->transportError,
			));
		}
		catch (Throwable $exception)
		{
			return $result->addError(UnexpectedErrorReporter::report($exception, 'update item'));
		}

		if ($outcome->error !== null)
		{
			return $result->addError($outcome->error);
		}

		$queue = $outcome->queue;
		$queueStatus = $queue->getStatus() ?? QueueStatus::Active;
		$itemDto = $outcome->itemDto;

		// A change to a nested file moves its folder row and the queue actions too; both
		// travel with the answer AND with the pull event, so neither this tab nor its
		// neighbours have to wait for a full-queue response to see them.
		$folderItems = [];
		$queueActions = [];
		$queueQuota = [];
		$queueStatuses = [];
		if ($outcome->stateChanged)
		{
			$projection = (new QueueChangeProjector($itemRepository, $resolver))
				->project($queue, $this->userId, [(int)$outcome->item->getId()])
			;
			$folderItems = $projection['folderItems'];
			// Keyed by queue so every item answer carries the same shape, retry included
			// (it spans queues). An untouched queue is absent, never an empty list - that
			// would read as "this queue offers nothing" and wipe its buttons.
			$queueActions = [(string)$queue->getId() => $projection['queueActions']];
			// Same keying for the quota classification: this very commit may have stopped
			// the queue by quota, and the banner CTA is chosen by it (AC-030 / AC-031).
			$queueQuota = [(string)$queue->getId() => $projection['queueQuota']];
			// ...and for the pair of statuses the pull event carries: the tab that sent this
			// request has no other way to hear the verdict of its own commit when the event
			// does not arrive (a box without the pull module, a dropped connection).
			$queueStatuses = [
				(string)$queue->getId() => [
					'status' => $queueStatus->value,
					'displayStatus' => $projection['queueDisplayStatus'],
				],
			];

			$this->notifier()->emit(
				$this->userId,
				(string)$outcome->item->getQueueId(),
				$queueStatus->value,
				array_merge([$itemDto], $folderItems),
				$projection['queueActions'],
				$projection['queueQuota'],
				$projection['queueDisplayStatus'],
			);
		}

		$result->setData([
			'item' => $itemDto,
			'folderItems' => $folderItems,
			'queueActions' => $queueActions,
			'queueQuota' => $queueQuota,
			'queueStatuses' => $queueStatuses,
		]);

		return $result;
	}

	private function notifier(): QueueEventNotifier
	{
		return $this->eventNotifier
			?? ServiceLocator::getInstance()->get('disk.dockedUpload.queueEventNotifier');
	}
}
