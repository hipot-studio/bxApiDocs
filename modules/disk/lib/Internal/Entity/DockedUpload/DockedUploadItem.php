<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Entity\DockedUpload;

use Bitrix\Disk\Internal\Enum\DockedUpload\ConflictPolicy;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemType;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

/**
 * Domain entity for a single docked upload element (a file or a folder-item).
 *
 * A folder-item groups the files uploaded inside a folder via PARENT_ITEM_ID
 * and carries the folder-level ConflictPolicy.
 */
final class DockedUploadItem implements EntityInterface
{
	protected ?int $id = null;
	protected ?int $queueId = null;
	protected ?int $parentItemId = null;
	protected ?ItemType $itemType = null;
	protected ?ItemStatus $status = null;
	protected ?string $name = null;
	protected ?string $relativePath = null;
	protected ?int $size = null;
	protected ?int $targetFolderId = null;
	protected ?int $targetFileId = null;
	protected ?int $storageId = null;
	protected ?ConflictPolicy $conflictPolicy = null;
	protected ?string $tempToken = null;
	protected ?string $intentToken = null;
	protected ?int $diskObjectId = null;
	protected ?string $errorCode = null;
	protected ?string $ownerClientId = null;
	protected ?DateTime $ownerHeartbeatAt = null;
	protected ?DateTime $createTime = null;
	protected ?DateTime $updateTime = null;

	/**
	 * Transient, never persisted: set in memory during a restore when this item's temp
	 * token was NOT verified because the per-request lookup ceiling truncated it
	 * (QueueRestoreProvider, P3.T3). Its interrupted_needs_file classification is then
	 * provisional - a later restore re-services it. It is not a DB column; the mapper
	 * neither reads nor writes it, mirroring the in-memory status mutation reclassify()
	 * already does on the read path.
	 */
	private bool $restoreTempUnchecked = false;

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(?int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getQueueId(): ?int
	{
		return $this->queueId;
	}

	public function setQueueId(?int $queueId): self
	{
		$this->queueId = $queueId;

		return $this;
	}

	public function getParentItemId(): ?int
	{
		return $this->parentItemId;
	}

	public function setParentItemId(?int $parentItemId): self
	{
		$this->parentItemId = $parentItemId;

		return $this;
	}

	public function getItemType(): ?ItemType
	{
		return $this->itemType;
	}

	public function setItemType(?ItemType $itemType): self
	{
		$this->itemType = $itemType;

		return $this;
	}

	public function getStatus(): ?ItemStatus
	{
		return $this->status;
	}

	public function setStatus(?ItemStatus $status): self
	{
		$this->status = $status;

		return $this;
	}

	public function getName(): ?string
	{
		return $this->name;
	}

	public function setName(?string $name): self
	{
		$this->name = $name;

		return $this;
	}

	public function getRelativePath(): ?string
	{
		return $this->relativePath;
	}

	public function setRelativePath(?string $relativePath): self
	{
		$this->relativePath = $relativePath;

		return $this;
	}

	public function getSize(): ?int
	{
		return $this->size;
	}

	public function setSize(?int $size): self
	{
		$this->size = $size;

		return $this;
	}

	public function getTargetFolderId(): ?int
	{
		return $this->targetFolderId;
	}

	public function setTargetFolderId(?int $targetFolderId): self
	{
		$this->targetFolderId = $targetFolderId;

		return $this;
	}

	public function getTargetFileId(): ?int
	{
		return $this->targetFileId;
	}

	public function setTargetFileId(?int $targetFileId): self
	{
		$this->targetFileId = $targetFileId;

		return $this;
	}

	public function getStorageId(): ?int
	{
		return $this->storageId;
	}

	public function setStorageId(?int $storageId): self
	{
		$this->storageId = $storageId;

		return $this;
	}

	public function getConflictPolicy(): ?ConflictPolicy
	{
		return $this->conflictPolicy;
	}

	public function setConflictPolicy(?ConflictPolicy $conflictPolicy): self
	{
		$this->conflictPolicy = $conflictPolicy;

		return $this;
	}

	public function getTempToken(): ?string
	{
		return $this->tempToken;
	}

	public function setTempToken(?string $tempToken): self
	{
		$this->tempToken = $tempToken;

		return $this;
	}

	public function getIntentToken(): ?string
	{
		return $this->intentToken;
	}

	public function setIntentToken(?string $intentToken): self
	{
		$this->intentToken = $intentToken;

		return $this;
	}

	public function getDiskObjectId(): ?int
	{
		return $this->diskObjectId;
	}

	public function setDiskObjectId(?int $diskObjectId): self
	{
		$this->diskObjectId = $diskObjectId;

		return $this;
	}

	public function getErrorCode(): ?string
	{
		return $this->errorCode;
	}

	public function setErrorCode(?string $errorCode): self
	{
		$this->errorCode = $errorCode;

		return $this;
	}

	public function getOwnerClientId(): ?string
	{
		return $this->ownerClientId;
	}

	public function setOwnerClientId(?string $ownerClientId): self
	{
		$this->ownerClientId = $ownerClientId;

		return $this;
	}

	public function getOwnerHeartbeatAt(): ?DateTime
	{
		return $this->ownerHeartbeatAt;
	}

	public function setOwnerHeartbeatAt(?DateTime $ownerHeartbeatAt): self
	{
		$this->ownerHeartbeatAt = $ownerHeartbeatAt;

		return $this;
	}

	public function getCreateTime(): ?DateTime
	{
		return $this->createTime;
	}

	public function setCreateTime(?DateTime $createTime): self
	{
		$this->createTime = $createTime;

		return $this;
	}

	public function getUpdateTime(): ?DateTime
	{
		return $this->updateTime;
	}

	public function setUpdateTime(?DateTime $updateTime): self
	{
		$this->updateTime = $updateTime;

		return $this;
	}

	public function isRestoreTempUnchecked(): bool
	{
		return $this->restoreTempUnchecked;
	}

	public function setRestoreTempUnchecked(bool $restoreTempUnchecked): self
	{
		$this->restoreTempUnchecked = $restoreTempUnchecked;

		return $this;
	}

	public function isFolder(): bool
	{
		return $this->itemType === ItemType::Folder;
	}
}
