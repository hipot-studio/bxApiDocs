<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Integration\Bitrix24Manager;
use Bitrix\Disk\ProxyType;
use Bitrix\Disk\Storage;

/**
 * Shared target-folder / storage resolution for uploads.
 *
 * It is the common part extracted from the legacy user-field upload controller
 * ({@see \Bitrix\Disk\Uf\Integration\DiskUploaderController}) and reused by the
 * docked uploader. It only resolves and validates access; it never writes files.
 *
 * Access is (re)checked here per invocation, which is what lets the docked commit
 * honour the invariant "permissions are re-verified per item on every commit,
 * not only when the item is queued".
 */
class FolderResolverService
{
	/**
	 * Upper bound on the memo. A pass aims a handful of distinct targets at once, so a
	 * real one never comes near the cap; reaching it means the caller is not a pass, and
	 * then the accelerator must not turn into an ever-growing map of live entities.
	 */
	private const MEMO_LIMIT = 64;

	/**
	 * Resolutions already answered for the pass currently in the memo, keyed by
	 * everything the answer depends on ({@see self::resolutionKey()}).
	 *
	 * A resolution is a read by primary key, and every element entering a transfer asks
	 * for its target while a batch aims them at the same folder: without this, eight
	 * elements cost eight identical SELECTs. Only the LOOKUP is reused - the access
	 * checks below stay per invocation, and they read the storage security context,
	 * which the storage runtime cache ({@see Storage::loadById()}) already shares
	 * whether the folder is re-read or not.
	 *
	 * The instance is NOT bounded by a request: it is registered with addInstanceLazy
	 * (disk/include.php, disk.dockedUpload.folderResolver), so it lives as long as the
	 * PROCESS - one request under FPM, a whole run under CLI or the cleanup agent. The
	 * memo is therefore bounded here instead, by {@see self::openMemoFor()}: it holds ONE
	 * user's pass at a time and at most self::MEMO_LIMIT entries within it. A long-lived
	 * process neither grows it without end nor answers a later user out of an earlier
	 * one's snapshot of Folder/Storage.
	 *
	 * @var array<string, array{0: Folder|null, 1: Storage|null}>
	 */
	private array $resolutions = [];

	/** The user whose pass the memo currently holds, or null while it has never been opened. */
	private ?int $memoUserId = null;

	public function __construct(
		private readonly Driver $driver,
	)
	{
	}

	/**
	 * Behaviour-preserving replacement for the controller's getFolderAndStorage:
	 * resolves an explicit folder by id, or the current user's upload/record
	 * folder when no folder id is given.
	 *
	 * @return array{0: Folder|null, 1: Storage|null}
	 */
	public function resolveFolderAndStorage(int $folderId, string $filename, int $userId): array
	{
		$this->openMemoFor($userId);

		return $this->resolutions[$this->resolutionKey($folderId, $filename, $userId)]
			??= $this->loadFolderAndStorage($folderId, $filename, $userId)
		;
	}

	/**
	 * Starts a fresh memo whenever the boundary it is scoped to is crossed: another user
	 * (a request serves exactly one, and the cleanup agent walks queues user by user), or
	 * the size cap. Dropping it is always safe - a miss costs only the read it saves.
	 */
	private function openMemoFor(int $userId): void
	{
		if ($this->memoUserId === $userId && count($this->resolutions) < self::MEMO_LIMIT)
		{
			return;
		}

		$this->resolutions = [];
		$this->memoUserId = $userId;
	}

	/**
	 * @return array{0: Folder|null, 1: Storage|null}
	 */
	private function loadFolderAndStorage(int $folderId, string $filename, int $userId): array
	{
		$folder = null;
		$storage = null;

		if ($folderId > 0)
		{
			$folder = Folder::load(['ID' => $folderId]);
			if ($folder !== null)
			{
				$storage = $folder->getStorage();
			}

			return [$folder, $storage];
		}

		$storage = $this->driver->getStorageByUserId($userId);
		if ($storage !== null)
		{
			$folder = $this->isRecordedFile($filename)
				? $storage->getFolderForRecordedFiles()
				: $storage->getFolderForUploadedFiles()
			;
		}

		return [$folder, $storage];
	}

	private function resolutionKey(int $folderId, string $filename, int $userId): string
	{
		// An explicit folder is a read by primary key: the user decides whether the access
		// checks pass, not which row is loaded. Without one the answer is the user's own
		// default folder, and the filename picks which of the two it is.
		return $folderId > 0
			? "folder:{$folderId}"
			: "default:{$userId}:" . (int)$this->isRecordedFile($filename)
		;
	}

	private function isRecordedFile(string $filename): bool
	{
		return mb_strpos($filename, 'videomessage') === 0;
	}

	/**
	 * Resolves the target folder for a docked new-file commit.
	 */
	public function resolveTargetFolder(int $folderId, int $userId): ?Folder
	{
		[$folder] = $this->resolveFolderAndStorage($folderId, '', $userId);

		return $folder;
	}

	/**
	 * Whether $userId is allowed to add a new file into the folder right now.
	 *
	 * The check is bound to the passed user (the queue owner), not to the ambient
	 * current user, so a docked commit re-verifies the right owner per item.
	 */
	public function canAddToFolder(Folder $folder, Storage $storage, int $userId): bool
	{
		return $folder->canAdd($storage->getSecurityContext($userId));
	}

	/**
	 * Whether $userId is allowed to write a new version of the file right now.
	 */
	public function canUpdateFile(File $file, Storage $storage, int $userId): bool
	{
		return $file->canUpdate($storage->getSecurityContext($userId));
	}

	/**
	 * Bitrix24 access restriction for the disk tool (STATUS_RESTRICTION). On box
	 * installs it is always allowed.
	 */
	public function isAccessRestricted(int $userId): bool
	{
		return !Bitrix24Manager::isAccessEnabled('disk', $userId);
	}

	/**
	 * Common storage is behind the disk_common_storage feature; block writes into
	 * it when the feature is off on the current tariff.
	 */
	public function isCommonStorageBlocked(Storage $storage): bool
	{
		if (!($storage->getProxyType() instanceof ProxyType\Common))
		{
			return false;
		}

		return !Bitrix24Manager::isFeatureEnabled('disk_common_storage');
	}

	/**
	 * Enqueue-time access verdict for the targets of a whole create batch (AC-055/056):
	 * the unique targets are answered together, so the pre-check costs a fixed number of
	 * lookups per target instead of one per element.
	 *
	 * The gate order is the commit-time one
	 * ({@see \Bitrix\Disk\Internal\Service\DockedUpload\FileCommitService}): the personal
	 * access restriction outranks everything, then the per-object permission, then a
	 * blocked common storage - so an element rejected here carries the code it would have
	 * carried after the transfer.
	 *
	 * A target that vanished never reaches here - it does not resolve out of b_disk_object
	 * at all - and its element is refused by the caller with the very ERROR_PERMISSION a
	 * forbidden target gets, so neither answer says whether the id exists. What DOES reach
	 * here is resolved, so a target failing to load through the typed accessor is refused
	 * rather than waved through - see {@see self::classifyUploadTarget()}.
	 *
	 * This does NOT move the gate: every commit still re-asks the permission per item,
	 * because rights change in both directions between the two moments. It only spares
	 * the user a transfer that was going to be refused.
	 *
	 * @param array<int, int> $folderTargets folder object id => storage id (a new file: canAdd)
	 * @param array<int, int> $fileTargets file object id => storage id (a new version: canUpdate)
	 * @return array<int, string> object id => docked ERROR_CODE, only for the blocked targets
	 */
	public function classifyUploadTargets(array $folderTargets, array $fileTargets, int $userId): array
	{
		if ($folderTargets === [] && $fileTargets === [])
		{
			return [];
		}

		// The disk tool is switched off per user, not per target: asked once for the batch.
		$isRestricted = $this->isAccessRestricted($userId);

		/** @var array<int, Storage|null> $storages */
		$storages = [];
		$blocks = [];
		foreach ([$folderTargets, $fileTargets] as $index => $targets)
		{
			$isVersionTarget = ($index === 1);
			foreach ($targets as $objectId => $storageId)
			{
				$code = $this->classifyUploadTarget(
					(int)$objectId,
					(int)$storageId,
					$userId,
					$isVersionTarget,
					$isRestricted,
					$storages,
				);
				if ($code !== null)
				{
					$blocks[(int)$objectId] = $code;
				}
			}
		}

		return $blocks;
	}

	/**
	 * Fail-closed: anything the gate cannot resolve is a refusal, never a permission.
	 *
	 * The caller resolved this target out of b_disk_object, and the typed accessors add
	 * exactly one filter the raw read has not - TYPE - so a null here means the id names an
	 * object of the other kind (targetFileId pointing at a folder, or the reverse). Waved
	 * through, such a target keeps a foreign STORAGE_ID on the row and travels back in the
	 * answer, and the same case already fails the item at commit time
	 * ({@see FileCommitService::commitNewVersion()}).
	 *
	 * The tariff verdict is asked LAST, after the per-object permission, which is what
	 * keeps this pre-check from answering questions about objects the caller may not see:
	 * a blocked common storage is a property of the storage, not of the caller, so
	 * answering it first would confirm that the id exists and sits in common storage to
	 * anyone who can name it. Whoever legitimately meets that block holds the right on
	 * the object and still gets the tariff code
	 * ({@see FileCommitService::classifyCommitAccessBlock()} keeps the same order).
	 *
	 * @param array<int, Storage|null> $storages storage id => storage, loaded once per batch
	 */
	private function classifyUploadTarget(
		int $objectId,
		int $storageId,
		int $userId,
		bool $isVersionTarget,
		bool $isRestricted,
		array &$storages,
	): ?string
	{
		if ($isRestricted)
		{
			return DisplayStatusResolver::ERROR_RESTRICTION;
		}

		if (!array_key_exists($storageId, $storages))
		{
			$storages[$storageId] = Storage::loadById($storageId);
		}

		$storage = $storages[$storageId];
		if ($storage === null)
		{
			return DisplayStatusResolver::ERROR_PERMISSION;
		}

		if (!$this->hasUploadTargetPermission($objectId, $storage, $userId, $isVersionTarget))
		{
			return DisplayStatusResolver::ERROR_PERMISSION;
		}

		if ($this->isCommonStorageBlocked($storage))
		{
			return DisplayStatusResolver::ERROR_COMMON_STORAGE_BLOCKED;
		}

		return null;
	}

	/**
	 * The per-object half of {@see self::classifyUploadTarget()}: a version target commits
	 * onto a file (canUpdate), a new file lands in a folder (canAdd). A target that fails
	 * to load through the typed accessor names an object of the other kind and is refused.
	 */
	private function hasUploadTargetPermission(
		int $objectId,
		Storage $storage,
		int $userId,
		bool $isVersionTarget,
	): bool
	{
		if ($isVersionTarget)
		{
			$file = File::loadById($objectId);

			return $file !== null && $this->canUpdateFile($file, $storage, $userId);
		}

		$folder = Folder::loadById($objectId);

		return $folder !== null && $this->canAddToFolder($folder, $storage, $userId);
	}
}
