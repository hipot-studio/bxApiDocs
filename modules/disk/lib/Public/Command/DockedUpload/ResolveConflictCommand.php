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
use Bitrix\Disk\Internal\Service\DockedUpload\FileCommitService;
use Bitrix\Disk\Internal\Service\DockedUpload\QueueChangeProjector;
use Bitrix\Disk\Internal\Service\DockedUpload\QueueEventNotifier;
use Bitrix\Disk\Internal\Service\DockedUpload\TempPresenceProbe;
use Bitrix\Disk\Internal\Service\DockedUpload\UnexpectedErrorReporter;
use Bitrix\Disk\Internals\ObjectNameService;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Main\Application;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\UI\FileUploader\Uploader;
use Throwable;

/**
 * Resolves a duplicate-name conflict on an item (API-01 resolveConflict).
 *
 * `replace` binds the item to the colliding target file and re-runs the
 * exactly-once commit as a new version; `cancel` cancels the item and frees its
 * temp file when the bytes are the caller's own ({@see self::releaseTemp()}).
 * The commit path is NOT wrapped in a command transaction (ALG-01).
 * The commit gate ({@see QueueStatus::acceptsCommit()}) is never bypassed: where it
 * is shut `replace` no-ops instead of committing.
 *
 * A `replace` commits a new version, i.e. runs the Edit-event autostart. When that
 * workflow has required parameters, the collected set travels here via
 * {@see $bizProcParameters} (SC-012 rework #2) and is passed straight into the commit
 * ({@see FileCommitService::commit()} -> runAfterEditWithInputParameters). Its
 * signed-ness is never taken from the client - {@see $bizProcParametersAreSigned} is
 * hardcoded false by the controller so server validation stays unconditional.
 */
class ResolveConflictCommand extends AbstractCommand
{
	public const ACTION_REPLACE = 'replace';
	public const ACTION_CANCEL = 'cancel';

	public function __construct(
		public readonly int $userId,
		public readonly int $itemId,
		public readonly string $action,
		public readonly array $bizProcParameters = [],
		public readonly bool $bizProcParametersAreSigned = false,
		private readonly ?FileCommitService $fileCommitService = null,
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

		$item = $itemRepository->getById($this->itemId);
		if ($item === null)
		{
			return $result->addError(new Error('Item not found', 'DISK_DOCKED_ITEM_NOT_FOUND'));
		}

		$queue = $queueRepository->getById((int)$item->getQueueId());
		if ($queue === null || (int)$queue->getUserId() !== $this->userId)
		{
			return $result->addError(new Error('Item not found', 'DISK_DOCKED_ITEM_NOT_FOUND'));
		}

		if ($item->getStatus() !== ItemStatus::DuplicateConflict)
		{
			return $result->addError(new Error('Item is not in a conflict state', 'DISK_DOCKED_NOT_CONFLICT'));
		}

		try
		{
			if ($this->action === self::ACTION_REPLACE)
			{
				$this->replace($item, $queue, $itemRepository);
			}
			elseif ($this->action === self::ACTION_CANCEL)
			{
				$this->cancel($item, $itemRepository, $queueRepository);
			}
			else
			{
				return $result->addError(new Error('Unknown action', 'DISK_DOCKED_UNKNOWN_ACTION'));
			}
		}
		catch (Throwable $exception)
		{
			return $result->addError(UnexpectedErrorReporter::report($exception, 'resolve conflict'));
		}

		$item = $itemRepository->getById($this->itemId) ?? $item;

		$queue = $queueRepository->getById((int)$item->getQueueId()) ?? $queue;
		$queueStatus = $queue->getStatus() ?? QueueStatus::Active;
		$itemDto = ItemDto::fromFileItem($item, $queueStatus, $resolver)->toArray();

		// Resolving a conflict inside a folder moves that folder's aggregate (and the
		// queue actions with it), so the recomputed rows travel with the answer and the
		// pull event instead of waiting for the next full-queue response.
		$projection = (new QueueChangeProjector($itemRepository, $resolver))
			->project($queue, $this->userId, [(int)$item->getId()])
		;

		$this->notifier()->emit(
			$this->userId,
			(string)$item->getQueueId(),
			$queueStatus->value,
			array_merge([$itemDto], $projection['folderItems']),
			$projection['queueActions'],
			$projection['queueQuota'],
			$projection['queueDisplayStatus'],
		);

		$result->setData([
			'item' => $itemDto,
			'folderItems' => $projection['folderItems'],
			'queueActions' => [(string)$queue->getId() => $projection['queueActions']],
			'queueQuota' => [(string)$queue->getId() => $projection['queueQuota']],
			// The pair the pull event above carries, keyed the same way: resolving the
			// conflict of the last unfinished element ends the queue, and the tab that
			// resolved it must see that without waiting for a best-effort event.
			'queueStatuses' => [
				(string)$queue->getId() => [
					'status' => $queueStatus->value,
					'displayStatus' => $projection['queueDisplayStatus'],
				],
			],
		]);

		return $result;
	}

	private function notifier(): QueueEventNotifier
	{
		return $this->eventNotifier
			?? ServiceLocator::getInstance()->get('disk.dockedUpload.queueEventNotifier');
	}

	private function replace(
		DockedUploadItem $item,
		DockedUploadQueue $queue,
		DockedUploadItemRepository $itemRepository,
	): void
	{
		// The SECOND commit point of the surface (the first is updateItem), so it answers to
		// the same gate: {@see QueueStatus::acceptsCommit()}, never acceptsWork(). The bytes
		// are already in temp and the conflict was raised by the commit itself, so bypassing
		// a stopped or dismissed queue costs not stranded work but a finished commit - an
		// object, a new version and the Edit-event autostart - where the user is waiting for
		// a restore or has closed the queue for good. A queue still pending is the opposite
		// case: its start is held up by the client, and dropping bytes it already paid the
		// traffic for would be the drift that split the two commit points apart. An
		// idempotent no-op, not an error: execute() answers the actual duplicate_conflict
		// state, so a stale tab is not told off about a queue it no longer shows.
		if (!($queue->getStatus() ?? QueueStatus::Active)->acceptsCommit())
		{
			return;
		}

		$targetFileId = $item->getTargetFileId();
		if ($targetFileId === null || $targetFileId <= 0)
		{
			$targetFileId = $this->findCollidingFileId((string)$item->getName(), (int)$item->getTargetFolderId());
		}

		// R4: the bind is a guarded transition, never a full-row save of the snapshot read
		// in execute() - the rule the automatic twin ({@see FileCommitService::applyReplaceAll()})
		// and cancel() right below already follow. A dismiss or a cancel landing in the
		// window between those reads and this write would otherwise be clobbered back to
		// uploaded_to_temp (and carried on to committed by the atomic update), leaving an
		// object, a new version and the Edit-event autostart in a queue the user has closed.
		// When the colliding file has vanished there is nothing to bind and the item stays a
		// file-add, but the move out of duplicate_conflict is guarded all the same - one
		// statement, so no transaction of its own; the commit owns its durability (ALG-01).
		$moved = $targetFileId === null
			? $itemRepository->updateStatusByIds(
				[(int)$item->getId()],
				ItemStatus::UploadedToTemp,
				$this->userId,
				['ERROR_CODE' => null],
			) === 1
			: $itemRepository->bindReplaceTarget((int)$item->getId(), $this->userId, $targetFileId)
		;

		if (!$moved)
		{
			// Lost the CAS (canceled or committed meanwhile): the row is terminal, so the
			// commit must not run. execute() re-reads and returns the actual state.
			return;
		}

		$item->setTargetFileId($targetFileId)->setStatus(ItemStatus::UploadedToTemp)->setErrorCode(null);

		$commitService = $this->fileCommitService
			?? ServiceLocator::getInstance()->get('disk.dockedUpload.fileCommitService');
		$commitService->commit($item, $this->bizProcParameters, $this->bizProcParametersAreSigned);
	}

	private function cancel(
		DockedUploadItem $item,
		DockedUploadItemRepository $itemRepository,
		DockedUploadQueueRepositoryInterface $queueRepository,
	): void
	{
		// The durable transition MUST land before the temp is freed: an atomic guarded
		// UPDATE duplicate_conflict -> canceled that refuses to overwrite a concurrent
		// committed (a Replace in a neighbouring tab moves the row out of the conflict and
		// commits it). A full-row save of the stale snapshot could roll that commit back and
		// re-open the exactly-once side-effect gate.
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$moved = $itemRepository->transitionStatus(
				(int)$item->getId(),
				$this->userId,
				ItemStatus::Canceled,
				[ItemStatus::DuplicateConflict],
			);

			$connection->commitTransaction();
		}
		catch (Throwable $exception)
		{
			$connection->rollbackTransaction();

			throw $exception;
		}

		if (!$moved)
		{
			// Lost the CAS (committed meanwhile): keep the temp, execute() re-reads and
			// returns the actual state.
			return;
		}

		// Cancelling a conflict item may have removed the queue's last unfinished leaf;
		// drain it on the same guarded predicate the commit and whole-queue cancel use.
		// execute() re-reads the queue afterwards, so the DTO/notifier reflect completion.
		$queueRepository->markCompletedIfDrained((int)$item->getQueueId());

		// Freeing the temp file is external and best-effort, and only after the durable
		// cancel won: keep it out of the command transaction (durability), same as
		// CancelCommand.
		$this->releaseTemp($item->getTempToken());
	}

	/**
	 * Frees the temp file of the canceled item, when the bytes are the caller's own.
	 *
	 * The third release of the surface, and it answers to the rule the other two follow
	 * ({@see DismissCommand::releaseTemp()}, {@see CancelCommand::releaseTemp()}) through the
	 * same filter: {@see Uploader::remove()} deletes a temp row by the GUID inside the token
	 * and never compares CREATED_BY, while the token on a row is whatever the client wrote
	 * there, so resolving a conflict on a row carrying a foreign token would otherwise drop
	 * somebody else's bytes. What the probe cannot confirm is not freed and stays with the
	 * TTL agent.
	 */
	private function releaseTemp(?string $tempToken): void
	{
		if ($tempToken === null || $tempToken === '')
		{
			return;
		}

		try
		{
			$ownTokens = $this->tempPresenceProbe->filterOwnPresent([$tempToken], $this->userId);
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

	private function findCollidingFileId(string $name, int $parentFolderId): ?int
	{
		if ($name === '' || $parentFolderId <= 0)
		{
			return null;
		}

		// Resolve the colliding object with the same service Disk uses to enforce
		// (NAME, PARENT_ID) uniqueness on add, so the resolved opponent matches the
		// name Disk actually stored (R10) instead of a hand-rolled exact-name query.
		$nameService = new ObjectNameService($name, $parentFolderId, ObjectTable::TYPE_FILE);
		$uniqueResult = $nameService->isUniqueName(returnOpponentId: true);
		if ($uniqueResult->isSuccess())
		{
			return null;
		}

		$opponentId = (int)($uniqueResult->getData()['opponentId'] ?? 0);
		if ($opponentId <= 0)
		{
			return null;
		}

		// ObjectNameService ignores TYPE / DELETED_TYPE - bind a replace only to a
		// live file, never to a folder or a trashed object under the same parent.
		$row = ObjectTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=ID' => $opponentId,
				'=TYPE' => ObjectTable::TYPE_FILE,
				'=DELETED_TYPE' => ObjectTable::DELETED_TYPE_NONE,
			],
			'limit' => 1,
		])->fetch();

		return $row ? (int)$row['ID'] : null;
	}
}
