<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

use Bitrix\Disk\Folder;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItem;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItemCollection;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Storage;

/**
 * Where a queue is uploading TO, in words: the storage title and the target folder name
 * the widget shows above the rows (CONTRACT-DTO-01, additive).
 *
 * The destination is read off the ELEMENTS, not off the queue: a queue is formally
 * heterogeneous - {@see \Bitrix\Disk\Public\Command\DockedUpload\CreateQueueCommand}
 * accepts a target per element - so TARGET_FOLDER_ID / STORAGE_ID live on the item row
 * and the queue owns no destination column of its own. The representative element is the
 * first TOP-LEVEL one naming a folder: on the folder-upload path the nested rows are
 * rebound to the sub-folders {@see FolderTreeBuilder} creates, while the top-level row
 * still names the folder the user actually dropped onto.
 *
 * Resolved ONCE per queue ({@see \Bitrix\Disk\Internal\Dto\DockedUpload\QueueDto::fromEntities()}
 * asks once for the whole element list, never per element), and the memo below shares the
 * answer between the queues of one restore, which routinely target the same folder. A
 * caller holding the whole batch answers it in one pass instead - {@see prefetch()}.
 *
 * The names are a DISCLOSURE, not a label, so they are published only to a user who may
 * read the destination: create deliberately does not verify targets, so a queue may name
 * an object aimed at by its id alone, and without the check any authorized user could read
 * the name of an arbitrary folder and the title of a foreign personal storage (which spells
 * out its owner). A destination that does not pass resolves to an empty
 * {@see QueueTargetNames} - the client renders the queue by its number there.
 */
final class QueueTargetNameResolver
{
	/**
	 * Upper bound on the memo, mirroring {@see FolderResolverService::MEMO_LIMIT}: a restore
	 * carries a handful of queues, so a real pass never comes near it, and a caller that is
	 * not a pass must not turn the accelerator into an ever-growing map of live entities.
	 */
	private const MEMO_LIMIT = 64;

	/** @var array<string, QueueTargetNames> destination key ({@see self::destinationKey()}) => names */
	private array $memo = [];

	/**
	 * @param array<int, string> $blockByTargetId target object id => enqueue-time access error
	 *   code, as {@see \Bitrix\Disk\Public\Command\DockedUpload\CreateQueueCommand} has just
	 *   computed it over the unique targets of the batch. A target already refused there is
	 *   answered from that verdict instead of asking the rights a second time in one request.
	 */
	public function __construct(private readonly array $blockByTargetId = [])
	{
	}

	public function resolve(DockedUploadItemCollection $items, int $userId): QueueTargetNames
	{
		[$folderId, $storageId] = self::pickDestination($items);
		if ($folderId <= 0 && $storageId <= 0)
		{
			return new QueueTargetNames();
		}

		if (isset($this->blockByTargetId[$folderId]))
		{
			return new QueueTargetNames();
		}

		if (count($this->memo) >= self::MEMO_LIMIT)
		{
			$this->memo = [];
		}

		return $this->memo[self::destinationKey($folderId, $storageId, $userId)]
			??= self::load($folderId, $storageId, $userId)
		;
	}

	/**
	 * Answers the destinations of a WHOLE batch of queues in one pass and leaves the
	 * verdicts in the memo, so the {@see resolve()} of each queue that follows costs no
	 * lookup of its own: two queries for the batch - one over its distinct folders, one
	 * over their storages - instead of a pair per distinct destination. The restore runs
	 * on every load of a Disk folder page, so its whole read path is batched
	 * ({@see \Bitrix\Disk\Public\Provider\DockedUpload\QueueRestoreProvider}).
	 *
	 * The ACCESS CHECK is deliberately not part of the batch: it stays the per-destination
	 * canRead() of {@see canSeeDestination()} - one question per distinct destination,
	 * exactly as many as the memo asks today, so nothing here loosens the gate. Both
	 * batched rights primitives of the module answer from another source than canRead()
	 * does (the SQL predicate of DiskSecurityContext::getSqlExpressionForList() reads
	 * b_disk_simple_right; preloadOperationsForSpecifiedObjects() reads the sibling rights
	 * unordered, while canRead() resolves a NEGATIVE right by depth), and a disclosure
	 * gate must not be decided by a second, subtly different authority.
	 *
	 * @param iterable<DockedUploadItemCollection> $itemsPerQueue element list of every
	 *   queue of the batch, in any order
	 * @param int $userId the owner the names are resolved FOR. A queue owned by anyone
	 *   else is simply not warmed up here: the memo is keyed by user, so that queue asks
	 *   its own question later instead of being answered from this pass.
	 */
	public function prefetch(iterable $itemsPerQueue, int $userId): void
	{
		$destinations = $this->collectDestinations($itemsPerQueue, $userId);
		if ($destinations === [])
		{
			return;
		}

		// A pass leaves the memo strictly BELOW the limit, because resolve() empties one that
		// has reached it: warmed right up to the limit, the whole pass would be dropped by the
		// very first queue reading it - two batched queries spent to buy back the old lookups.
		if (count($this->memo) + count($destinations) >= self::MEMO_LIMIT)
		{
			$this->memo = [];
		}
		$destinations = array_slice($destinations, 0, self::MEMO_LIMIT - 1, true);

		$folders = self::loadFolders($destinations);
		self::warmStorageCache($destinations, $folders);

		foreach ($destinations as $key => [$folderId, $storageId])
		{
			$this->memo[$key] = self::buildNames($folders[$folderId] ?? null, $storageId, $userId);
		}
	}

	/**
	 * The DISTINCT destinations of the batch still missing from the memo, keyed the way
	 * {@see resolve()} will look them up. A destination refused at enqueue is dropped
	 * here for the same reason resolve() drops it - the verdict is already known.
	 *
	 * @param iterable<DockedUploadItemCollection> $itemsPerQueue
	 * @return array<string, array{0: int, 1: int}> destination key => folder id, storage id
	 */
	private function collectDestinations(iterable $itemsPerQueue, int $userId): array
	{
		$destinations = [];
		foreach ($itemsPerQueue as $items)
		{
			[$folderId, $storageId] = self::pickDestination($items);
			if ($folderId <= 0 && $storageId <= 0)
			{
				continue;
			}
			if (isset($this->blockByTargetId[$folderId]))
			{
				continue;
			}

			$key = self::destinationKey($folderId, $storageId, $userId);
			if (!isset($this->memo[$key]))
			{
				// First queue wins the slot, the way the ??= of resolve() gives it to the
				// first asker: two queues share a key while naming DIFFERENT storages only
				// when their common folder is already gone, and the answer of the batch
				// must not depend on the order the rows came back in.
				$destinations[$key] ??= [$folderId, $storageId];
			}
		}

		return $destinations;
	}

	/**
	 * The distinct folders of the batch in one query. The TYPE filter is what
	 * {@see Folder::loadById()} carries and {@see \Bitrix\Disk\Internals\Model::loadBatchById()}
	 * does not: without it a destination id that names a FILE today would come back as an
	 * object here, while the per-queue path answers null for it.
	 *
	 * @param array<string, array{0: int, 1: int}> $destinations
	 * @return array<int, Folder> folder id => folder
	 */
	private static function loadFolders(array $destinations): array
	{
		$folderIds = [];
		foreach ($destinations as [$folderId])
		{
			if ($folderId > 0)
			{
				$folderIds[$folderId] = $folderId;
			}
		}

		if ($folderIds === [])
		{
			return [];
		}

		$folders = [];
		$rows = Folder::getModelList([
			'select' => ['*'],
			'filter' => [
				'@ID' => array_values($folderIds),
				'TYPE' => ObjectTable::TYPE_FOLDER,
			],
		]);
		foreach ($rows as $folder)
		{
			$folders[(int)$folder->getId()] = $folder;
		}

		return $folders;
	}

	/**
	 * Reads every storage the batch may name - the loaded folders' own storages, plus the
	 * stamped STORAGE_ID of the destinations that resolved no folder - through the batch
	 * primitive of the model, which stores what it read in the request-wide storage cache
	 * {@see Storage::loadById()} answers from. {@see buildNames()} then finds each storage
	 * in memory, and the queues sharing one keep sharing its security context.
	 *
	 * @param array<string, array{0: int, 1: int}> $destinations
	 * @param array<int, Folder> $folders
	 */
	private static function warmStorageCache(array $destinations, array $folders): void
	{
		$storageIds = [];
		foreach ($folders as $folder)
		{
			$storageId = (int)$folder->getStorageId();
			if ($storageId > 0)
			{
				$storageIds[$storageId] = $storageId;
			}
		}
		foreach ($destinations as [$folderId, $storageId])
		{
			if ($storageId > 0 && !isset($folders[$folderId]))
			{
				$storageIds[$storageId] = $storageId;
			}
		}

		if ($storageIds === [])
		{
			return;
		}

		Storage::loadBatchById(array_values($storageIds), ['ROOT_OBJECT']);
	}

	/**
	 * @return array{0: int, 1: int} target folder id and storage id of the queue, 0 for an
	 *   element list that names neither (an element whose target had already vanished at
	 *   enqueue carries no storage either - create deliberately does not verify targets)
	 */
	private static function pickDestination(DockedUploadItemCollection $items): array
	{
		$topLevelFolderId = 0;
		$anyFolderId = 0;
		$storageId = 0;

		foreach ($items as $item)
		{
			$itemFolderId = (int)($item->getTargetFolderId() ?? 0);
			$itemStorageId = (int)($item->getStorageId() ?? 0);

			if ($topLevelFolderId <= 0 && $itemFolderId > 0 && self::isTopLevel($item))
			{
				$topLevelFolderId = $itemFolderId;
			}
			if ($anyFolderId <= 0 && $itemFolderId > 0)
			{
				$anyFolderId = $itemFolderId;
			}
			if ($storageId <= 0 && $itemStorageId > 0)
			{
				$storageId = $itemStorageId;
			}

			if ($topLevelFolderId > 0 && $storageId > 0)
			{
				break;
			}
		}

		return [$topLevelFolderId > 0 ? $topLevelFolderId : $anyFolderId, $storageId];
	}

	private static function isTopLevel(DockedUploadItem $item): bool
	{
		$parentItemId = $item->getParentItemId();

		return $parentItemId === null || $parentItemId <= 0;
	}

	private static function destinationKey(int $folderId, int $storageId, int $userId): string
	{
		// The folder answers both names, so a queue that named one is keyed by it alone;
		// only a queue with no folder at all (a version upload names targetFileId) falls
		// back to the storage it was stamped with. The user is part of the key because the
		// answer is theirs: the access check below may refuse the same destination to the next.
		$destination = $folderId > 0 ? "folder:{$folderId}" : "storage:{$storageId}";

		return "user:{$userId}:{$destination}";
	}

	private static function load(int $folderId, int $storageId, int $userId): QueueTargetNames
	{
		return self::buildNames($folderId > 0 ? Folder::loadById($folderId) : null, $storageId, $userId);
	}

	/**
	 * The single place a destination turns into names, whichever pass brought the folder
	 * in. Both lookups here answer from memory once {@see warmStorageCache()} has run.
	 */
	private static function buildNames(?Folder $folder, int $storageId, int $userId): QueueTargetNames
	{
		// The folder's own storage wins over the stamped STORAGE_ID: the column is
		// denormalised and may go stale (a queue lives up to a day), the folder is live.
		$storage = $folder?->getStorage() ?? ($storageId > 0 ? Storage::loadById($storageId) : null);
		if ($storage === null || !self::canSeeDestination($folder, $storage, $userId))
		{
			return new QueueTargetNames();
		}

		return new QueueTargetNames(
			$storage->getProxyType()->getTitleForCurrentUser(),
			self::resolveFolderName($folder, $storage),
		);
	}

	/**
	 * Read access on the representative of the destination, taken through the storage
	 * security context of the queue owner - the way rights on a disk object are decided,
	 * never by comparing ids. Read, not write: the question here is whether the user may
	 * LEARN the destination, and a folder they can open holds no secret even when the
	 * upload into it is refused. A storage without a folder answers through its root.
	 */
	private static function canSeeDestination(?Folder $folder, Storage $storage, int $userId): bool
	{
		$securityContext = $storage->getSecurityContext($userId);

		return (bool)($folder?->canRead($securityContext) ?? $storage->canRead($securityContext));
	}

	/**
	 * The root folder carries no name a user would recognise (it IS the storage), so the
	 * root resolves to null and the client shows the storage alone.
	 */
	private static function resolveFolderName(?Folder $folder, Storage $storage): ?string
	{
		if ($folder === null || (int)$storage->getRootObjectId() === (int)$folder->getId())
		{
			return null;
		}

		$name = (string)$folder->getName();

		return $name === '' ? null : $name;
	}
}
