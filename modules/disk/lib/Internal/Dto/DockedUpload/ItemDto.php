<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Dto\DockedUpload;

use Bitrix\Disk\Internal\Entity\DockedUpload\DockedFolderAggregate;
use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItem;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;
use Bitrix\Disk\Internal\Enum\DockedUpload\QueueStatus;
use Bitrix\Disk\Internal\Service\DockedUpload\DisplayStatusResolver;
use Bitrix\Main\Type\DateTime;
use JsonSerializable;

/**
 * Serializable payload of a single docked upload element (DTO-01).
 *
 * The backend fills displayStatus and availableActions (via
 * {@see DisplayStatusResolver}); the frontend is a pure view over them. Elements
 * are addressed as a flat list: a nested one names its owner through parentItemId,
 * while a folder-item additionally carries the rolled-up aggregate of its sub-tree
 * (itemsCount + size), so it still renders as a single row.
 */
final class ItemDto implements JsonSerializable
{
	/**
	 * @param string[] $availableActions
	 * @param string|null $parentItemId itemId of the owning folder-item, null for a
	 *   top-level element; it is what rebuilds the tree on restore, where there is
	 *   no clientId echo to match against (DTO-01).
	 * @param string|null $ownerClientId the tab currently holding the transport of
	 *   this item, null when unowned. A neighbouring tab needs it to name a dead
	 *   owner as knownStaleClientId on updateItem (P4.T1); ownership is a leaf
	 *   transport concern, so a folder-item never carries it.
	 * @param int|null $ownerHeartbeatAgeSeconds how long ago the owner last
	 *   heartbeated, measured by the server against its own clock, so the client's
	 *   liveness thresholds (P4.T1: they are client-side) never depend on the two
	 *   clocks agreeing. Null when there is no heartbeat on the row.
	 * @param int|null $targetFolderId the disk folder the element lands in, null when
	 *   it has none: a folder-item is structural (its disk folder is created later),
	 *   and a version element names its destination through targetFileId instead
	 *   ({@see \Bitrix\Disk\Public\Command\DockedUpload\CreateQueueCommand} accepts
	 *   both). A missing destination is null, never a 0 sentinel.
	 * @param int|null $targetFileId the disk file this element commits a new version onto,
	 *   null when it creates a fresh file. A resolved duplicate replace (and a direct
	 *   version upload) binds it, so the commit runs the Edit-event autostart instead of
	 *   Create ({@see \Bitrix\Disk\Internal\Service\DockedUpload\FileCommitService::commit()}
	 *   dispatches on exactly this). The client reads it to drive the byte-less retry of a
	 *   failed-for-parameters row through the matching (storage, scenario) pair. A missing
	 *   binding is null, never a 0 sentinel, symmetric with targetFolderId.
	 * @param string|null $clientId echo of the create-request clientId; only set in
	 *   the create response, always null on the restore / pull paths (DTO-01).
	 * @param string|null $infoHelperCode item-level InfoHelper slider code, opened by
	 *   the same popup mechanism as {@see \Bitrix\Disk\Internal\Dto\DockedUpload\QueueDto::$infoHelperCode};
	 *   today only a common storage switched off by the tariff carries one (ERR-016).
	 *   Filled by {@see DisplayStatusResolver::resolveInfoHelperCode()} from the item
	 *   error code, null when not applicable. The client never computes it.
	 * @param int|null $skippedCount folder-item: how many of its files ended without
	 *   landing (skipped by the conflict policy or cancelled). The client shows the
	 *   number in the folder row instead of deducing it from a progress bar that
	 *   stops short of the file count; null on a leaf.
	 * @param bool $restoreTempUnchecked true when this item's temp was NOT verified in
	 *   the current restore because the per-request lookup ceiling truncated it (P3.T3):
	 *   its interrupted_needs_file is then provisional and a later restore re-services
	 *   it, so the client tells "temp really gone" (false) from "not checked this
	 *   restore" (true). Always false outside restore (pull, mutation responses) and
	 *   whenever the check did run; a folder-item has no temp, so it is false there too.
	 * @param int|null $storageId the disk storage this element lands in, stamped at enqueue
	 *   from its target (a folder-item inherits the root of its tree). The BizProc parameter
	 *   set is keyed by the (storage, scenario) pair and BOTH members must be read off the
	 *   ELEMENT: the client holds one widget context per tab and any folder.list instance
	 *   overwrites it, so a restored row of a foreign storage would otherwise collect the
	 *   set of the page it was reloaded on (AC-050/AC-051/AC-078). Nullable because a target
	 *   that no longer exists resolves to nothing - create does not verify it - and because
	 *   an older row predating the column carries none; the client then falls back to its own
	 *   context. A missing storage is null, never a 0 sentinel.
	 *
	 *   NOT a source of truth for the commit. The value is denormalised and may go stale: a
	 *   queue lives up to a day ({@see \Bitrix\Disk\Public\Command\DockedUpload\CreateQueueCommand}
	 *   QUEUE_TTL_SECONDS) and moving an object between storages is a copy plus a markDeleted,
	 *   so the target this was resolved from can be gone by the time the bytes land. The
	 *   consequence is bounded because the field feeds only the BizProc parameter set: the
	 *   commit re-resolves the storage from the live folder
	 *   ({@see \Bitrix\Disk\Internal\Service\DockedUpload\FileCommitService::commit()} loads
	 *   TARGET_FOLDER_ID and reads its storage), so where the file actually lands never
	 *   depends on this column.
	 */
	public function __construct(
		public readonly string $itemId,
		public readonly ?string $parentItemId,
		public readonly string $type,
		public readonly string $status,
		public readonly string $displayStatus,
		public readonly array $availableActions,
		public readonly string $name,
		public readonly ?int $size,
		public readonly ?int $itemsCount,
		public readonly ?int $readyCount,
		public readonly ?int $readySize,
		public readonly ?int $skippedCount,
		public readonly ?int $targetFolderId,
		public readonly ?string $errorCode,
		public readonly ?string $tempToken,
		public readonly ?string $ownerClientId = null,
		public readonly ?int $ownerHeartbeatAgeSeconds = null,
		public readonly ?string $clientId = null,
		public readonly ?string $infoHelperCode = null,
		public readonly bool $restoreTempUnchecked = false,
		public readonly ?int $targetFileId = null,
		public readonly ?int $storageId = null,
	)
	{
	}

	/**
	 * Builds the DTO for a leaf (file) item.
	 */
	public static function fromFileItem(
		DockedUploadItem $item,
		QueueStatus $queueStatus,
		DisplayStatusResolver $resolver,
		?string $clientId = null,
	): self
	{
		$status = $item->getStatus() ?? ItemStatus::Pending;
		$errorCode = $item->getErrorCode();

		return new self(
			itemId: (string)$item->getId(),
			parentItemId: self::resolveParentItemId($item),
			type: $item->getItemType()?->value ?? '',
			status: $status->value,
			displayStatus: $resolver->resolveItemDisplayStatus($status, $queueStatus, $errorCode),
			availableActions: $resolver->resolveItemActions($status, $queueStatus, $errorCode),
			name: (string)$item->getName(),
			size: $item->getSize(),
			itemsCount: null,
			readyCount: null,
			readySize: null,
			skippedCount: null,
			targetFolderId: self::resolveTargetFolderId($item),
			errorCode: $errorCode,
			tempToken: $item->getTempToken(),
			ownerClientId: $item->getOwnerClientId(),
			ownerHeartbeatAgeSeconds: self::resolveHeartbeatAge($item),
			clientId: $clientId,
			infoHelperCode: $resolver->resolveInfoHelperCode($errorCode),
			restoreTempUnchecked: $item->isRestoreTempUnchecked(),
			targetFileId: self::resolveTargetFileId($item),
			storageId: self::resolveStorageId($item),
		);
	}

	/**
	 * Builds the DTO for a folder-item, rolling up the child aggregate. The
	 * folder display status is derived from the aggregate (blocking child wins),
	 * without unfolding the tree.
	 *
	 * A folder-item never carries its own progress, so the aggregate is also what
	 * ends it: once no child has work left the folder is terminal - `committed`
	 * while at least one file landed (AC-072: the skipped ones are reported through
	 * skippedCount, they do not hold the folder back), `canceled` when none did.
	 *
	 * Where the aggregate has nothing to say the row falls back to its OWN terminal
	 * status - cancelled by a cascade, or refused by the enqueue itself - so a folder
	 * that will never move is published with its verdict, and with the error code
	 * behind it, instead of sitting in `queued` with no reason attached. A folder that
	 * carries no file at all has neither, and nothing but the queue being over ever
	 * ends it, so that last case is answered from the queue status.
	 */
	public static function fromFolderItem(
		DockedUploadItem $folderItem,
		DockedFolderAggregate $aggregate,
		QueueStatus $queueStatus,
		DisplayStatusResolver $resolver,
		?string $clientId = null,
	): self
	{
		$ownStatus = $folderItem->getStatus() ?? ItemStatus::Pending;
		$blocking = $aggregate->getBlockingError();

		// The folder derives one status + error code from its aggregate; displayStatus
		// AND availableActions both flow from it, so the folder can be cancelled /
		// retried by the same matrix as a leaf (AC-015/AC-016, ERR-002) instead of an
		// empty action list.
		if ($blocking !== null)
		{
			$derivedStatus = $blocking->getStatus() ?? ItemStatus::Failed;
			$derivedErrorCode = $blocking->getErrorCode();
		}
		elseif ($aggregate->getTotalCount() > 0 && $aggregate->isFinished())
		{
			$derivedStatus = $aggregate->getReadyCount() > 0 ? ItemStatus::Committed : ItemStatus::Canceled;
			$derivedErrorCode = null;
		}
		elseif ($ownStatus === ItemStatus::Failed)
		{
			// The enqueue refused the row itself ({@see \Bitrix\Disk\Public\Command\DockedUpload\CreateQueueCommand}
			// rejects an unstorable RELATIVE_PATH whatever the element type), and a folder
			// refused before it ever had a child has no aggregate to speak for it. Ranked
			// below the aggregate on purpose: the files nested under it are enqueued, bound
			// and committed by their OWN relative paths, so what they are doing is the
			// stronger answer while they still have work left.
			$derivedStatus = ItemStatus::Failed;
			$derivedErrorCode = $folderItem->getErrorCode();
		}
		elseif ($ownStatus === ItemStatus::Canceled)
		{
			// Nothing to derive from: an empty folder has no children at all, and a
			// cancelled one whose cascade is still landing would otherwise read as
			// "queued" - the folder's own verdict is terminal either way.
			$derivedStatus = ItemStatus::Canceled;
			$derivedErrorCode = null;
		}
		elseif ($queueStatus === QueueStatus::Completed && $aggregate->getTotalCount() === 0)
		{
			// A folder whose whole sub-tree holds no file is created by
			// {@see \Bitrix\Disk\Public\Command\DockedUpload\CreateFolderTreeCommand} and
			// leaves nothing to commit afterwards, so no writer ever moves its row. The
			// aggregate branch above is what ends every other folder, and this one has no
			// aggregate to be ended by, so the end is read off the QUEUE: the AC-077 drain
			// that closed it is the very transition that finished this build.
			//
			// Committed rather than the aggregate's ready-count verdict: with no child there
			// is no readyCount to succeed with, yet the folder itself did land, and
			// "cancelled" would tell the user a folder that stands on the Disk was never
			// created.
			//
			// Completed alone, never dismissed: a closed widget reports no outcome, and the
			// queues still open belong to the branch below - a tree may be built by an
			// asynchronous stepper, so an emptiness seen mid-build is not an end yet.
			$derivedStatus = ItemStatus::Committed;
			$derivedErrorCode = null;
		}
		else
		{
			// In progress or not started yet - reuse the pending composition so a
			// quota-stopped queue renders the folder as blocked too.
			$derivedStatus = $aggregate->getReadyCount() > 0
				? ItemStatus::Uploading
				: ItemStatus::Pending;
			$derivedErrorCode = null;
		}

		return new self(
			itemId: (string)$folderItem->getId(),
			parentItemId: self::resolveParentItemId($folderItem),
			type: $folderItem->getItemType()?->value ?? '',
			status: $ownStatus->value,
			displayStatus: $resolver->resolveItemDisplayStatus($derivedStatus, $queueStatus, $derivedErrorCode),
			availableActions: $resolver->resolveItemActions($derivedStatus, $queueStatus, $derivedErrorCode),
			name: (string)$folderItem->getName(),
			size: $aggregate->getRolledSize(),
			itemsCount: $aggregate->getTotalCount(),
			readyCount: $aggregate->getReadyCount(),
			readySize: $aggregate->getReadySize(),
			skippedCount: $aggregate->getSkippedCount(),
			targetFolderId: self::resolveTargetFolderId($folderItem),
			// The DERIVED code, the very one displayStatus and availableActions were composed
			// from: a row whose reason came from somewhere other than a blocking child would
			// otherwise publish a caption without the code behind it.
			errorCode: $derivedErrorCode,
			tempToken: null,
			clientId: $clientId,
			infoHelperCode: $resolver->resolveInfoHelperCode($derivedErrorCode),
			storageId: self::resolveStorageId($folderItem),
		);
	}

	/**
	 * Mirrors PARENT_ITEM_ID as a string, so the client can match it against itemId
	 * as-is. A non-positive link means the element was never attached to a
	 * folder-item, mirroring {@see \Bitrix\Disk\Internal\Service\DockedUpload\FileCommitService}.
	 */
	private static function resolveParentItemId(DockedUploadItem $item): ?string
	{
		$parentItemId = $item->getParentItemId();

		return $parentItemId === null || $parentItemId <= 0 ? null : (string)$parentItemId;
	}

	/**
	 * Mirrors TARGET_FOLDER_ID, normalising a non-positive value to null: the column
	 * is nullable and an element without a destination folder (a folder-item, or a
	 * file targeting targetFileId for a new version) may already hold 0 on the row.
	 */
	private static function resolveTargetFolderId(DockedUploadItem $item): ?int
	{
		$targetFolderId = $item->getTargetFolderId();

		return $targetFolderId === null || $targetFolderId <= 0 ? null : $targetFolderId;
	}

	/**
	 * Mirrors TARGET_FILE_ID, normalising a non-positive value to null: an element that
	 * creates a fresh file carries no version target, and the column may hold 0 on the row.
	 * A positive value is the file this element commits a new version onto (a resolved
	 * replace or a direct version upload) - the same predicate {@see FileCommitService}
	 * uses to take the version branch.
	 */
	private static function resolveTargetFileId(DockedUploadItem $item): ?int
	{
		$targetFileId = $item->getTargetFileId();

		return $targetFileId === null || $targetFileId <= 0 ? null : $targetFileId;
	}

	/**
	 * Mirrors STORAGE_ID, normalising a non-positive value to null: the column is nullable
	 * and an element whose target had already vanished when the queue was created carries
	 * none. The client reads it as the first member of the (storage, scenario) pair, falling
	 * back to its own widget context only when it is null - so a 0 sentinel would be taken
	 * for a real storage and silently defeat that fallback.
	 */
	private static function resolveStorageId(DockedUploadItem $item): ?int
	{
		$storageId = $item->getStorageId();

		return $storageId === null || $storageId <= 0 ? null : $storageId;
	}

	/**
	 * Exposes the heartbeat as an age against the server's own clock instead of the
	 * absolute OWNER_HEARTBEAT_AT: the client owns the liveness thresholds (P4.T1)
	 * but not a clock synchronised with the server, and comparing a server timestamp
	 * against Date.now() would declare an owner dead (or immortal) purely from clock
	 * drift. A negative diff (a heartbeat written under a clock adjustment) clamps
	 * to 0 - "just seen".
	 */
	private static function resolveHeartbeatAge(DockedUploadItem $item): ?int
	{
		$heartbeatAt = $item->getOwnerHeartbeatAt();
		if ($heartbeatAt === null)
		{
			return null;
		}

		return max(0, (new DateTime())->getTimestamp() - $heartbeatAt->getTimestamp());
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}

	public function toArray(): array
	{
		return [
			'itemId' => $this->itemId,
			'parentItemId' => $this->parentItemId,
			'type' => $this->type,
			'status' => $this->status,
			'displayStatus' => $this->displayStatus,
			'availableActions' => $this->availableActions,
			'name' => $this->name,
			'size' => $this->size,
			'itemsCount' => $this->itemsCount,
			'readyCount' => $this->readyCount,
			'readySize' => $this->readySize,
			'skippedCount' => $this->skippedCount,
			'targetFolderId' => $this->targetFolderId,
			'errorCode' => $this->errorCode,
			'tempToken' => $this->tempToken,
			'ownerClientId' => $this->ownerClientId,
			'ownerHeartbeatAgeSeconds' => $this->ownerHeartbeatAgeSeconds,
			'clientId' => $this->clientId,
			'infoHelperCode' => $this->infoHelperCode,
			'restoreTempUnchecked' => $this->restoreTempUnchecked,
			'targetFileId' => $this->targetFileId,
			'storageId' => $this->storageId,
		];
	}
}
