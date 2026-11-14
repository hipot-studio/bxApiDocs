<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\DockedUpload;

use Bitrix\Disk\Internal\Dto\DockedUpload\QueueDto;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItem;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItemCollection;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadQueue;
use Bitrix\Disk\Internal\Enum\DockedUpload\ConflictPolicy;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemType;
use Bitrix\Disk\Internal\Enum\DockedUpload\QueueStatus;
use Bitrix\Disk\Internal\Model\DockedUploadItemTable;
use Bitrix\Disk\Internal\Repository\DockedUpload\DockedUploadItemRepository;
use Bitrix\Disk\Internal\Repository\Interface\DockedUploadQueueRepositoryInterface;
use Bitrix\Disk\Internal\Service\DockedUpload\DisplayStatusResolver;
use Bitrix\Disk\Internal\Service\DockedUpload\FolderResolverService;
use Bitrix\Disk\Internal\Service\DockedUpload\QueueTargetNameResolver;
use Bitrix\Disk\Internal\Service\DockedUpload\UnexpectedErrorReporter;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Main\Application;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Throwable;

/**
 * Enqueues a fresh docked upload queue for the user (API-01 create).
 *
 * Each file element runs its pre-checks before any bytes are transferred, and each
 * one that fails is stored straight as failed - the queued -> error transition of
 * PRD section 3 - with the code the display layer renders it by:
 * - the AC-073/ERR-018 per-file size cap (docked_file_size);
 * - an empty file, which the commit would refuse anyway (docked_file_empty);
 * - the AC-055/056 access gates over the resolved target (restriction /
 *   common_storage_blocked / permission_fail).
 *
 * Every rejection is item-level: the rest of the batch is enqueued and runs (AC-035).
 * The queue keeps its stored status - it is DISPLAYED as requiring action by the
 * composition {@see DisplayStatusResolver::resolveQueueDisplayStatus()} makes.
 */
class CreateQueueCommand extends AbstractCommand
{
	/**
	 * Queue lifetime - a 1-day TTL kept in sync with the temp-file window, so a
	 * queue never outlives the temp files its items still point at.
	 */
	private const QUEUE_TTL_SECONDS = 86400;

	/** Wait budget (seconds) for the per-user create lock (Q-6), matching the folder-tree stepper. */
	private const USER_CEILING_LOCK_TIMEOUT = 10;

	/**
	 * @param array<int, array<string, mixed>> $items raw queue elements
	 */
	public function __construct(
		public readonly int $userId,
		public readonly array $items,
		public readonly ?ConflictPolicy $conflictPolicy = null,
		private readonly ?DockedUploadItemRepository $itemRepository = null,
		private readonly ?DockedUploadQueueRepositoryInterface $queueRepository = null,
		private readonly ?DisplayStatusResolver $resolver = null,
		private readonly ?int $userMaxActiveItems = null,
		private readonly ?FolderResolverService $folderResolver = null,
		private readonly ?int $userMaxRetainedItems = null,
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

		$targetError = $this->validateItemTargets();
		if ($targetError !== null)
		{
			return $result->addError($targetError);
		}

		// The per-user ceilings must hold against the same user racing several sessions,
		// so the COUNTs and the insert run inside one per-user connection lock (Q-6): a
		// bare COUNT before the insert would let two concurrent creates both pass the
		// check and both insert. Direct callers pass no limit and skip the lock.
		return $this->withUserCeilingLock(
			fn(): Result => $this->createUnderCeiling($result, $itemRepository, $queueRepository, $resolver),
		);
	}

	private function createUnderCeiling(
		Result $result,
		DockedUploadItemRepository $itemRepository,
		DockedUploadQueueRepositoryInterface $queueRepository,
		DisplayStatusResolver $resolver,
	): Result
	{
		$limitError = $this->enforceUserItemCeiling($itemRepository);
		if ($limitError !== null)
		{
			return $result->addError($limitError);
		}

		$maxFileSize = $this->effectiveMaxFileSize();
		// Resolved once for the whole batch, before the transaction: it is a pure read and
		// the queue is formally heterogeneous (targets are per-element), so the storage is
		// stamped per element rather than assumed for the queue.
		$storageIdByObjectId = $this->resolveTargetStorages();
		// AC-055/056: the access pre-check rides the very same batch resolution, over the
		// UNIQUE targets - a hundred files going into one folder ask about that folder once.
		$blockByObjectId = $this->classifyTargetAccess($storageIdByObjectId);

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			// A fresh queue is pending, not active: it has not moved a byte yet, and it
			// may not start at all until the BizProc parameter set of its (storage,
			// scenario) pair is collected (AC-051). The first transfer in it promotes it
			// ({@see \Bitrix\Disk\Internal\Service\DockedUpload\ItemMutationApplier}).
			$queue = (new DockedUploadQueue())
				->setUserId($this->userId)
				->setStatus(QueueStatus::Pending)
				->setExpiresAt(DateTime::createFromTimestamp(time() + self::QUEUE_TTL_SECONDS))
			;
			$queueRepository->save($queue);

			$queueId = (int)$queue->getId();

			// Build every element together with its client tree keys (clientId /
			// parentClientId) so the PARENT_ITEM_ID links can be materialized once
			// the parents own a server id.
			$built = [];
			foreach ($this->items as $raw)
			{
				$built[] = $this->buildItem($queueId, $raw, $maxFileSize, $storageIdByObjectId, $blockByObjectId);
			}

			// Two-phase insert to materialize the folder tree:
			//   1. folder-items first, per-row via save() to read back the generated
			//      ids, in topological order so a nested folder can point at its
			//      already-inserted parent through PARENT_ITEM_ID;
			//   2. the remaining leaf items in a single multi-row saveMany(), now
			//      carrying PARENT_ITEM_ID resolved from the folder id map.
			// Without client keys (legacy client) this degrades to the old flat
			// insert: no folder pass, no PARENT_ITEM_ID, one saveMany.
			$clientIdToServerId = $this->insertFolderItems($built, $itemRepository);
			$this->insertLeafItems($built, $clientIdToServerId, $itemRepository);

			$connection->commitTransaction();
		}
		catch (Throwable $exception)
		{
			$connection->rollbackTransaction();
			$result->addError(UnexpectedErrorReporter::report($exception, 'create queue'));

			return $result;
		}

		$items = $itemRepository->getByQueue((int)$queue->getId(), $this->userId);
		$clientIdByItemId = $this->buildClientIdEchoMap($built, $items);
		// The destination names ride the access verdicts this request has already taken over
		// the very same targets, so a refused one is answered from them instead of asking
		// the rights a second time.
		$dto = QueueDto::fromEntities(
			$queue,
			$items,
			$resolver,
			$clientIdByItemId,
			new QueueTargetNameResolver($blockByObjectId),
		);

		// API-01 create answers with the whole QueueDto (DTO-01), not just its id and
		// items: availableActions / status / totalSize are what the client renders the
		// queue from, and create is the only moment a session-born queue gets them.
		$result->setData($dto->toArray());

		return $result;
	}

	/**
	 * Serializes the COUNT + insert on a per-user connection lock (GET_LOCK on MySQL /
	 * pg_advisory on PG), so the Q-6 ceiling stays authoritative against one user
	 * opening several sessions. The lock is taken only when a ceiling is actually set,
	 * spans the whole critical section, and is always released - a connection lock is
	 * independent of the DB transaction, so it holds across commit/rollback.
	 *
	 * @param callable(): Result $critical the ceiling checks and the insert
	 */
	private function withUserCeilingLock(callable $critical): Result
	{
		if (!self::isCeilingSet($this->userMaxActiveItems) && !self::isCeilingSet($this->userMaxRetainedItems))
		{
			return $critical();
		}

		if (!$this->acquireUserCeilingLock($this->userId))
		{
			return (new Result())->addError(new Error(
				'User active item limit lock unavailable',
				'DISK_DOCKED_LIMIT_USER',
			));
		}

		try
		{
			return $critical();
		}
		finally
		{
			$this->releaseUserCeilingLock($this->userId);
		}
	}

	protected function acquireUserCeilingLock(int $userId): bool
	{
		return Application::getConnection()->lock(self::userCeilingLockKey($userId), self::USER_CEILING_LOCK_TIMEOUT);
	}

	protected function releaseUserCeilingLock(int $userId): void
	{
		Application::getConnection()->unlock(self::userCeilingLockKey($userId));
	}

	private static function userCeilingLockKey(int $userId): string
	{
		return 'disk_docked_create_user_' . $userId;
	}

	/**
	 * Rejects a file element with no valid destination: a file must target a folder
	 * (targetFolderId) or an existing file for a new version (targetFileId). Folder
	 * elements are structural (their disk folder is created later by createFolderTree)
	 * and carry no file destination, so they are exempt.
	 */
	private function validateItemTargets(): ?Error
	{
		foreach ($this->items as $raw)
		{
			$type = ItemType::tryFrom((string)($raw['type'] ?? '')) ?? ItemType::File;
			if ($type === ItemType::Folder)
			{
				continue;
			}

			$targetFolderId = isset($raw['targetFolderId']) ? (int)$raw['targetFolderId'] : 0;
			$targetFileId = isset($raw['targetFileId']) ? (int)$raw['targetFileId'] : 0;
			if ($targetFolderId <= 0 && $targetFileId <= 0)
			{
				return new Error('Item has no valid target', 'DISK_DOCKED_ITEM_INVALID_TARGET');
			}
		}

		return null;
	}

	/**
	 * Enforces the two cumulative per-user ceilings (Q-6). create is the sole insert
	 * point, so the size of the new batch plus what the user already holds guards each
	 * total. Either limit is skipped when it is not supplied (direct callers pass neither).
	 *
	 * They bound two different things and both are needed:
	 * - the ACTIVE ceiling bounds the work in flight, and closing a queue frees it at
	 *   once - that is what keeps finished folder uploads from blocking new ones;
	 * - the RETAINED ceiling bounds the rows the user keeps in the table, and closing a
	 *   queue frees nothing of it: a dismiss (or a cancel that drains a folder-only queue
	 *   to completed) leaves every row in place for the whole 24h TTL, so without this
	 *   second ceiling a close-and-refill loop would write rows at the request rate, with
	 *   the cleanup agent collecting 20 queues an hour behind it.
	 */
	private function enforceUserItemCeiling(DockedUploadItemRepository $itemRepository): ?Error
	{
		$batchSize = count($this->items);

		if (self::isCeilingSet($this->userMaxActiveItems))
		{
			// A total, not the overflow form the RETAINED ceiling uses below: that form reads the
			// same rows while the user is under a ceiling, and pays to produce every row it skips.
			$active = $itemRepository->countActiveItemsByUser($this->userId);
			if ($active + $batchSize > $this->userMaxActiveItems)
			{
				return new Error('User active item limit exceeded', 'DISK_DOCKED_LIMIT_USER');
			}
		}

		if (self::isCeilingSet($this->userMaxRetainedItems))
		{
			// Asked as an overflow, not as a total: that bounds the read by the ceiling itself, so a
			// user already past it does not pay a COUNT that grows with the table inside the per-user
			// create lock. Under the ceiling both forms read the same rows. Same refusals -
			// retained + batchSize > ceiling is retained > ceiling - batchSize.
			$roomForBatch = $this->userMaxRetainedItems - $batchSize;
			if ($itemRepository->hasRetainedItemsOverLimit($this->userId, $roomForBatch))
			{
				return new Error('User retained item limit exceeded', 'DISK_DOCKED_LIMIT_USER_RETAINED');
			}
		}

		return null;
	}

	private static function isCeilingSet(?int $ceiling): bool
	{
		return $ceiling !== null && $ceiling > 0;
	}

	/**
	 * Resolves the storage of every target named by the batch with ONE query. Folders and
	 * files share {@see ObjectTable} (b_disk_object carries STORAGE_ID), so targetFolderId
	 * and targetFileId are looked up together over the unique ids. A target that no longer
	 * exists is simply absent from the map, and its element is then refused by
	 * {@see self::rejectBlockedTarget()} with the same code a forbidden target earns - the
	 * read stays a plain storage lookup and never becomes the answer to "does this id exist".
	 *
	 * @return array<int, int> object id => storage id
	 */
	private function resolveTargetStorages(): array
	{
		$objectIds = [];
		foreach ($this->items as $raw)
		{
			foreach (['targetFolderId', 'targetFileId'] as $key)
			{
				$objectId = isset($raw[$key]) ? (int)$raw[$key] : 0;
				if ($objectId > 0)
				{
					$objectIds[$objectId] = true;
				}
			}
		}

		if ($objectIds === [])
		{
			return [];
		}

		$storageIdByObjectId = [];
		foreach (ObjectTable::getList([
			'select' => ['ID', 'STORAGE_ID'],
			'filter' => ['@ID' => array_keys($objectIds)],
		]) as $row)
		{
			$storageIdByObjectId[(int)$row['ID']] = (int)$row['STORAGE_ID'];
		}

		return $storageIdByObjectId;
	}

	/**
	 * The resolved target of an element: the object its storage is stamped from and the
	 * one the access pre-check asks about, so both read the element the same way.
	 *
	 * The version target wins over the folder one: targetFileId names the exact object the
	 * element commits onto, while on the folder-upload path targetFolderId is only the root
	 * placeholder createFolderTree rebinds later. They agree in practice - a file lives in
	 * the storage of its parent folder - so this only settles the order.
	 *
	 * The choice is made on the key the element NAMED, not on the first one that happened to
	 * resolve: an element naming a targetFileId that no longer exists must be refused, not
	 * quietly re-gated against its targetFolderId - the commit branch is picked from the
	 * stored TARGET_FILE_ID regardless
	 * ({@see \Bitrix\Disk\Internal\Service\DockedUpload\FileCommitService::commit()}), so
	 * the pre-check would otherwise measure an object the transfer never lands on.
	 *
	 * @param array<string, mixed> $raw
	 * @param array<int, int> $storageIdByObjectId
	 */
	private static function resolveItemTargetId(array $raw, array $storageIdByObjectId): ?int
	{
		foreach (['targetFileId', 'targetFolderId'] as $key)
		{
			$objectId = isset($raw[$key]) ? (int)$raw[$key] : 0;
			if ($objectId > 0)
			{
				return ($storageIdByObjectId[$objectId] ?? 0) > 0 ? $objectId : null;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $raw
	 * @param array<int, int> $storageIdByObjectId
	 */
	private static function resolveItemStorageId(array $raw, array $storageIdByObjectId): ?int
	{
		$objectId = self::resolveItemTargetId($raw, $storageIdByObjectId);

		return $objectId === null ? null : $storageIdByObjectId[$objectId];
	}

	/**
	 * AC-055/056: the enqueue-time access verdict per resolved target, taken over the
	 * UNIQUE targets of the batch ({@see FolderResolverService::classifyUploadTargets()})
	 * so it adds no query per element. Only file elements are asked about: a folder-item
	 * builds its branch through
	 * {@see \Bitrix\Disk\Internal\Service\DockedUpload\FolderTreeBuilder}, which takes the
	 * same gates on the root before it creates anything.
	 *
	 * @param array<int, int> $storageIdByObjectId
	 * @return array<int, string> object id => error code, only for the blocked targets
	 */
	private function classifyTargetAccess(array $storageIdByObjectId): array
	{
		$folderTargets = [];
		$fileTargets = [];
		foreach ($this->items as $raw)
		{
			$type = ItemType::tryFrom((string)($raw['type'] ?? '')) ?? ItemType::File;
			$objectId = $type === ItemType::File ? self::resolveItemTargetId($raw, $storageIdByObjectId) : null;
			if ($objectId === null)
			{
				continue;
			}

			// Which gate the target rides is decided by which of the two keys resolved it:
			// a version commits onto a file (canUpdate), a new file lands in a folder (canAdd).
			if ((int)($raw['targetFileId'] ?? 0) === $objectId)
			{
				$fileTargets[$objectId] = $storageIdByObjectId[$objectId];
			}
			else
			{
				$folderTargets[$objectId] = $storageIdByObjectId[$objectId];
			}
		}

		if ($folderTargets === [] && $fileTargets === [])
		{
			return [];
		}

		$folderResolver = $this->folderResolver
			?? ServiceLocator::getInstance()->get('disk.dockedUpload.folderResolver');

		return $folderResolver->classifyUploadTargets($folderTargets, $fileTargets, $this->userId);
	}

	/**
	 * @param array<string, mixed> $raw
	 * @param array<int, int> $storageIdByObjectId object id => storage id, for the whole batch
	 * @param array<int, string> $blockByObjectId object id => access error code, for the blocked targets
	 *
	 * @return array{item: DockedUploadItem, clientId: string|null, parentClientId: string|null}
	 */
	private function buildItem(
		int $queueId,
		array $raw,
		int $maxFileSize,
		array $storageIdByObjectId,
		array $blockByObjectId,
	): array
	{
		$type = ItemType::tryFrom((string)($raw['type'] ?? '')) ?? ItemType::File;
		$size = isset($raw['size']) ? (int)$raw['size'] : null;

		$item = (new DockedUploadItem())
			->setQueueId($queueId)
			->setItemType($type)
			->setStatus(ItemStatus::Pending)
			->setName((string)($raw['name'] ?? ''))
			->setSize($size)
			->setTargetFolderId(isset($raw['targetFolderId']) ? (int)$raw['targetFolderId'] : null)
			->setStorageId(self::resolveItemStorageId($raw, $storageIdByObjectId))
		;

		$relativePath = empty($raw['relativePath']) ? '' : (string)$raw['relativePath'];
		$pathRejection = self::rejectUnstorablePath($relativePath);
		if ($relativePath !== '' && $pathRejection === null)
		{
			$item->setRelativePath($relativePath);
		}
		if (!empty($raw['targetFileId']))
		{
			$item->setTargetFileId((int)$raw['targetFileId']);
		}
		if (!empty($raw['tempToken']))
		{
			$item->setTempToken((string)$raw['tempToken']);
		}
		if ($type === ItemType::Folder)
		{
			$item->setConflictPolicy($this->conflictPolicy ?? ConflictPolicy::default());
		}

		// The pre-checks, in the order the element meets them: the size cap is the local
		// one (AC-073 / ERR-018), the access verdict the one taken over its target
		// (AC-055/056, ERR-014/015/016). Either way the element goes queued -> error
		// before a byte is spent, and the first reason found is the one it carries.
		$accessBlock = self::rejectBlockedTarget($type, $raw, $storageIdByObjectId, $blockByObjectId);
		$rejection = self::rejectEmpty($type, $size)
			?? self::rejectOversized($type, $size, $maxFileSize)
			?? $accessBlock
			?? $pathRejection
		;
		if ($rejection !== null)
		{
			$item->setStatus(ItemStatus::Failed)->setErrorCode($rejection);
		}
		if ($accessBlock !== null)
		{
			// A target the access gate refused is not stored on the row: every access code
			// is non-retriable, so the element has no use for the ids, while a kept pointer
			// would let a later read of the queue answer questions about an object its
			// owner may not see.
			$item->setTargetFolderId(null)->setStorageId(null);
		}

		// API-01 durable client tree keys: clientId is a per-request stable id of
		// the element, parentClientId is the clientId of the owning folder (null
		// for top-level). PARENT_ITEM_ID is stamped from these at insert time.
		return [
			'item' => $item,
			'clientId' => self::normalizeClientKey($raw['clientId'] ?? null),
			'parentClientId' => self::normalizeClientKey($raw['parentClientId'] ?? null),
		];
	}

	private static function rejectEmpty(ItemType $type, ?int $size): ?string
	{
		return $type === ItemType::File && $size === 0 ? DisplayStatusResolver::ERROR_FILE_EMPTY : null;
	}

	private static function rejectOversized(ItemType $type, ?int $size, int $maxFileSize): ?string
	{
		$isOversized = $type === ItemType::File && $maxFileSize > 0 && $size !== null && $size > $maxFileSize;

		return $isOversized ? DisplayStatusResolver::ERROR_FILE_SIZE : null;
	}

	/**
	 * The access verdict an element carries for its target, and the point at which a target
	 * that does not resolve is refused exactly like one the caller may not write to.
	 *
	 * Both answers are ERROR_PERMISSION on purpose: telling them apart would make create a
	 * free existence oracle over b_disk_object - an authenticated user could walk ids and
	 * read "pending" as "no such object" against "failed" as "it exists". The three product
	 * codes stay distinct where they say something about the CALLER rather than about the
	 * object (ERR-014/015/016): a personal restriction, and a tariff block, which is only
	 * reachable once the per-object permission has passed
	 * ({@see FolderResolverService::classifyUploadTarget()}).
	 *
	 * Folder elements are exempt: they are structural, carry no file destination, and their
	 * root is gated by createFolderTree.
	 *
	 * @param array<string, mixed> $raw
	 * @param array<int, int> $storageIdByObjectId
	 * @param array<int, string> $blockByObjectId
	 */
	private static function rejectBlockedTarget(
		ItemType $type,
		array $raw,
		array $storageIdByObjectId,
		array $blockByObjectId,
	): ?string
	{
		if ($type !== ItemType::File)
		{
			return null;
		}

		$objectId = self::resolveItemTargetId($raw, $storageIdByObjectId);
		if ($objectId === null)
		{
			// The element named a target (validateItemTargets() already refused one that
			// named none) and it did not resolve: gone, or never there.
			return DisplayStatusResolver::ERROR_PERMISSION;
		}

		return $blockByObjectId[$objectId] ?? null;
	}

	/**
	 * Builds the create-response clientId echo map (server itemId => request
	 * clientId, DTO-01). It is only ever used for the create response - the key is
	 * never persisted.
	 *
	 * Folder-items are inserted per-row (save()) and already carry their server id,
	 * so they map directly. Leaf-items are inserted with a single multi-row
	 * statement that reports no per-row ids ({@see DockedUploadItemRepository::saveMany()}),
	 * so they are correlated by insertion order: the statement assigns consecutive
	 * ids in row order, hence the re-read leaves ordered by id line up 1:1 with the
	 * built leaf sequence.
	 *
	 * @param array<int, array{item: DockedUploadItem, clientId: string|null, parentClientId: string|null}> $built
	 *
	 * @return array<int, string> server itemId => clientId
	 */
	private function buildClientIdEchoMap(array $built, DockedUploadItemCollection $reread): array
	{
		$map = [];

		/** @var array<int, string|null> $leafClientIds request clientIds in insertion order */
		$leafClientIds = [];
		foreach ($built as $entry)
		{
			$item = $entry['item'];
			if ($item->isFolder())
			{
				$id = $item->getId();
				if ($entry['clientId'] !== null && $id !== null)
				{
					$map[(int)$id] = $entry['clientId'];
				}
			}
			else
			{
				$leafClientIds[] = $entry['clientId'];
			}
		}

		$rereadLeaves = [];
		foreach ($reread as $item)
		{
			if (!$item->isFolder())
			{
				$rereadLeaves[] = $item;
			}
		}
		usort($rereadLeaves, static fn(DockedUploadItem $a, DockedUploadItem $b): int => (int)$a->getId() <=> (int)$b->getId());

		foreach ($rereadLeaves as $index => $item)
		{
			$clientId = $leafClientIds[$index] ?? null;
			if ($clientId !== null)
			{
				$map[(int)$item->getId()] = $clientId;
			}
		}

		return $map;
	}

	private static function normalizeClientKey(mixed $value): ?string
	{
		if (!is_string($value) && !is_int($value))
		{
			return null;
		}

		$value = (string)$value;

		return $value === '' ? null : $value;
	}

	/**
	 * Phase 1 - insert folder-items per-row (save()) so their generated ids can
	 * be read back, walking parents before children so a nested folder receives
	 * PARENT_ITEM_ID of its already-inserted parent folder.
	 *
	 * @param array<int, array{item: DockedUploadItem, clientId: string|null, parentClientId: string|null}> $built
	 *
	 * @return array<string, int> map clientId => generated server itemId
	 */
	private function insertFolderItems(array $built, DockedUploadItemRepository $itemRepository): array
	{
		$map = [];

		$folders = [];
		$folderClientIds = [];
		foreach ($built as $entry)
		{
			if ($entry['item']->isFolder())
			{
				$folders[] = $entry;
				if ($entry['clientId'] !== null)
				{
					$folderClientIds[$entry['clientId']] = true;
				}
			}
		}

		// Order the folders parent-before-child once, instead of repeatedly rescanning
		// a pending set (which was O(depth x N) for a reversed chain). Folders that stay
		// unordered - a cycle in parentClientId, or a parent that never materializes -
		// are inserted afterwards as roots.
		[$ordered, $unordered] = self::orderFoldersParentFirst($folders, $folderClientIds);

		foreach ($ordered as $entry)
		{
			$item = $entry['item'];
			$parentClientId = $entry['parentClientId'];
			if ($parentClientId !== null && isset($folderClientIds[$parentClientId]))
			{
				// Parent-first order guarantees the in-batch parent already owns a server id.
				$item->setParentItemId($map[$parentClientId]);
			}
			$itemRepository->save($item);
			if ($entry['clientId'] !== null)
			{
				$map[$entry['clientId']] = (int)$item->getId();
			}
		}

		// Cycle or dangling in-batch parent reference: insert as roots (no
		// PARENT_ITEM_ID) so a broken chain can never loop forever.
		foreach ($unordered as $entry)
		{
			$item = $entry['item'];
			$itemRepository->save($item);
			if ($entry['clientId'] !== null)
			{
				$map[$entry['clientId']] = (int)$item->getId();
			}
		}

		return $map;
	}

	/**
	 * Kahn layering of the in-batch folder-items: parents precede children, and the
	 * original built order is preserved within each depth (so ids stay assigned level
	 * by level, as the old pending-scan did). Roots are folders with no in-batch folder
	 * parent. Folders unreachable from any root - a cycle in parentClientId, or a parent
	 * that never materializes - are returned separately to be inserted as roots.
	 *
	 * @param array<int, array{item: DockedUploadItem, clientId: string|null, parentClientId: string|null}> $folders
	 * @param array<string, true> $folderClientIds
	 * @return array{
	 *     0: array<int, array{item: DockedUploadItem, clientId: string|null, parentClientId: string|null}>,
	 *     1: array<int, array{item: DockedUploadItem, clientId: string|null, parentClientId: string|null}>
	 * }
	 */
	private static function orderFoldersParentFirst(array $folders, array $folderClientIds): array
	{
		$childIndexesByParent = [];
		$depth = [];
		$queue = [];
		foreach ($folders as $index => $entry)
		{
			$parentClientId = $entry['parentClientId'];
			if ($parentClientId !== null && isset($folderClientIds[$parentClientId]))
			{
				$childIndexesByParent[$parentClientId][] = $index;
			}
			else
			{
				$depth[$index] = 0;
				$queue[] = $index;
			}
		}

		$maxDepth = 0;
		for ($head = 0; isset($queue[$head]); $head++)
		{
			$index = $queue[$head];
			$clientId = $folders[$index]['clientId'];
			if ($clientId === null)
			{
				continue;
			}

			foreach ($childIndexesByParent[$clientId] ?? [] as $childIndex)
			{
				if (!isset($depth[$childIndex]))
				{
					$depth[$childIndex] = $depth[$index] + 1;
					$maxDepth = max($maxDepth, $depth[$childIndex]);
					$queue[] = $childIndex;
				}
			}
		}

		// Bucket the reachable folders by depth in one built-order pass, then
		// concatenate the buckets so parents (lower depth) precede children while the
		// original order is kept within each depth.
		$buckets = [];
		$unordered = [];
		foreach ($folders as $index => $entry)
		{
			if (isset($depth[$index]))
			{
				$buckets[$depth[$index]][] = $entry;
			}
			else
			{
				$unordered[] = $entry;
			}
		}

		$ordered = [];
		for ($d = 0; $d <= $maxDepth; $d++)
		{
			foreach ($buckets[$d] ?? [] as $entry)
			{
				$ordered[] = $entry;
			}
		}

		return [$ordered, $unordered];
	}

	/**
	 * Phase 2 - insert every non-folder element in a single multi-row saveMany(),
	 * stamping PARENT_ITEM_ID from the folder id map when the item declares an
	 * in-batch parent folder.
	 *
	 * @param array<int, array{item: DockedUploadItem, clientId: string|null, parentClientId: string|null}> $built
	 * @param array<string, int> $clientIdToServerId
	 */
	private function insertLeafItems(array $built, array $clientIdToServerId, DockedUploadItemRepository $itemRepository): void
	{
		$batch = new DockedUploadItemCollection();
		foreach ($built as $entry)
		{
			$item = $entry['item'];
			if ($item->isFolder())
			{
				continue;
			}

			$parentClientId = $entry['parentClientId'];
			if ($parentClientId !== null && isset($clientIdToServerId[$parentClientId]))
			{
				$item->setParentItemId($clientIdToServerId[$parentClientId]);
			}

			$batch->add($item);
		}

		$itemRepository->saveMany($batch);
	}

	/**
	 * Effective per-file cap: min of the docked ceiling and the php.ini transfer
	 * limit. It is NOT the storage quota (Q-3) - this is the docked + php.ini
	 * source (Q-6).
	 *
	 * The ui side contributes the php.ini reading only. A bare
	 * {@see \Bitrix\UI\FileUploader\Configuration} is the DEFAULT of that module and
	 * knows nothing of this transport - the docked ceiling is the one
	 * {@see \Bitrix\Disk\Controller\DockedUploader\Transport::getConfiguration()} puts on
	 * the wire - so reading it here would silently cap the pre-check by a value the
	 * transport never enforces the moment the two defaults stop coinciding.
	 */
	private function effectiveMaxFileSize(): int
	{
		$configuredMax = $this->configuredMaxFileSize();
		$phpMax = Loader::includeModule('ui')
			? \Bitrix\UI\FileUploader\Configuration::getPhpMaxUploadSize()
			: 0
		;

		return $phpMax > 0 ? min($configuredMax, $phpMax) : $configuredMax;
	}

	/**
	 * The docked per-file ceiling (Q-6), single-sourced with the transport
	 * ({@see \Bitrix\Disk\Controller\DockedUploader\Transport::MAX_FILE_SIZE}).
	 * Isolated as a seam so the pre-check can be exercised against a cap this stand
	 * can actually reach.
	 */
	protected function configuredMaxFileSize(): int
	{
		return \Bitrix\Disk\Configuration::DOCKED_UPLOAD_MAX_FILE_SIZE;
	}

	/**
	 * RELATIVE_PATH is a sized column and create is what persists it: a path wider
	 * than the column reaches the driver either as an exception (strict MySQL,
	 * PostgreSQL) that rolls the WHOLE batch back with an opaque internal error, or -
	 * without strict mode - as a silent truncation, after which
	 * {@see \Bitrix\Disk\Internal\Service\DockedUpload\FolderTreeBuilder::bindFileItems()}
	 * binds the file into a folder nobody asked for. The cap is read off the ORM map,
	 * so it cannot drift from the column.
	 *
	 * Item-level and non-retriable, like every other create pre-check: the branch this
	 * element asks for can never be materialised, and the rest of the batch is enqueued
	 * and runs (AC-035). The depth and per-segment caps stay on createFolderTree, the
	 * surface that actually creates a folder per level.
	 */
	private static function rejectUnstorablePath(string $relativePath): ?string
	{
		if ($relativePath === '')
		{
			return null;
		}

		$maxLength = (int)DockedUploadItemTable::getEntity()->getField('RELATIVE_PATH')->getSize();

		return $maxLength > 0 && mb_strlen($relativePath) > $maxLength
			? DisplayStatusResolver::ERROR_FOLDER_TREE_FAILED
			: null
		;
	}
}
