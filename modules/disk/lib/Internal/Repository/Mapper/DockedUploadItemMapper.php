<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository\Mapper;

use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItem;
use Bitrix\Disk\Internal\Enum\DockedUpload\ConflictPolicy;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemType;
use Bitrix\Disk\Internal\Model\DockedUploadItemTable;
use Bitrix\Disk\Internal\Model\EO_DockedUploadItem;
use Bitrix\Main\Type\DateTime;
use LogicException;

class DockedUploadItemMapper
{
	public function convertFromOrm(EO_DockedUploadItem $ormModel): DockedUploadItem
	{
		return
			(new DockedUploadItem())
				->setId($ormModel->getId())
				->setQueueId($ormModel->getQueueId())
				->setParentItemId($ormModel->getParentItemId())
				->setItemType($this->resolveItemType($ormModel->getItemType()))
				->setStatus($this->resolveStatus($ormModel->getStatus()))
				->setName($ormModel->getName())
				->setRelativePath($ormModel->getRelativePath())
				->setSize($ormModel->getSize())
				->setTargetFolderId($ormModel->getTargetFolderId())
				->setTargetFileId($ormModel->getTargetFileId())
				->setStorageId($ormModel->getStorageId())
				->setConflictPolicy($this->resolveConflictPolicy($ormModel->getConflictPolicy()))
				->setTempToken($ormModel->getTempToken())
				->setIntentToken($ormModel->getIntentToken())
				->setDiskObjectId($ormModel->getDiskObjectId())
				->setErrorCode($ormModel->getErrorCode())
				->setOwnerClientId($ormModel->getOwnerClientId())
				->setOwnerHeartbeatAt($ormModel->getOwnerHeartbeatAt())
				->setCreateTime($ormModel->getCreateTime())
				->setUpdateTime($ormModel->getUpdateTime())
			;
	}

	/**
	 * Builds the very same entity as {@see self::convertFromOrm()} straight from a result
	 * row, for the streaming collection reads of
	 * {@see \Bitrix\Disk\Internal\Repository\DockedUpload\DockedUploadItemRepository}.
	 *
	 * The row must be one produced by Query::exec()->fetch(). What such a row already
	 * carries, and what it does not, is decided by the DB column types rather than by
	 * getMap() (ORM\Query\Result leaves its strict field converters off):
	 * - datetime columns arrive as Type\DateTime (or null) - the driver-type converter
	 *   builds them, and the object path adds nothing to them beyond the user-time flag
	 *   they already carry;
	 * - integer columns arrive as raw strings, no converter is registered for them - hence
	 *   toNullableInt(), which is what IntegerField::cast() does on the object path;
	 * - varchar columns (including the enum-valued ones) arrive as-is.
	 *
	 * A column left out of a narrowed setSelect() is absent from the row and reads as null
	 * here, the same way it reads as null on the PARTIAL entity object a narrowed
	 * fetchCollection() produces. ITEM_TYPE and STATUS are the exception: they are read
	 * without a fallback, so a select dropping them fails loudly instead of yielding an
	 * element with no type - exactly as convertFromOrm() fails on such an object.
	 *
	 * @param array<string, mixed> $row
	 */
	public function convertFromArray(array $row): DockedUploadItem
	{
		return
			(new DockedUploadItem())
				->setId($this->toNullableInt($row['ID'] ?? null))
				->setQueueId($this->toNullableInt($row['QUEUE_ID'] ?? null))
				->setParentItemId($this->toNullableInt($row['PARENT_ITEM_ID'] ?? null))
				->setItemType($this->resolveItemType($row['ITEM_TYPE']))
				->setStatus($this->resolveStatus($row['STATUS']))
				->setName($row['NAME'] ?? null)
				->setRelativePath($row['RELATIVE_PATH'] ?? null)
				->setSize($this->toNullableInt($row['SIZE'] ?? null))
				->setTargetFolderId($this->toNullableInt($row['TARGET_FOLDER_ID'] ?? null))
				->setTargetFileId($this->toNullableInt($row['TARGET_FILE_ID'] ?? null))
				->setStorageId($this->toNullableInt($row['STORAGE_ID'] ?? null))
				->setConflictPolicy($this->resolveConflictPolicy($row['CONFLICT_POLICY'] ?? null))
				->setTempToken($row['TEMP_TOKEN'] ?? null)
				->setIntentToken($row['INTENT_TOKEN'] ?? null)
				->setDiskObjectId($this->toNullableInt($row['DISK_OBJECT_ID'] ?? null))
				->setErrorCode($row['ERROR_CODE'] ?? null)
				->setOwnerClientId($row['OWNER_CLIENT_ID'] ?? null)
				->setOwnerHeartbeatAt($row['OWNER_HEARTBEAT_AT'] ?? null)
				->setCreateTime($row['CREATE_TIME'] ?? null)
				->setUpdateTime($row['UPDATE_TIME'] ?? null)
			;
	}

	public function convertToOrm(DockedUploadItem $entity): EO_DockedUploadItem
	{
		$id = $entity->getId();

		$ormModel = is_int($id)
			? EO_DockedUploadItem::wakeUp($id)
			: DockedUploadItemTable::createObject()
		;

		$ormModel
			->setQueueId($entity->getQueueId())
			->setParentItemId($entity->getParentItemId())
			->setItemType($entity->getItemType()?->value)
			->setStatus($entity->getStatus()?->value)
			->setName($entity->getName())
			->setRelativePath($entity->getRelativePath())
			->setSize($entity->getSize())
			->setTargetFolderId($entity->getTargetFolderId())
			->setTargetFileId($entity->getTargetFileId())
			->setStorageId($entity->getStorageId())
			->setConflictPolicy($entity->getConflictPolicy()?->value)
			->setTempToken($entity->getTempToken())
			->setIntentToken($entity->getIntentToken())
			->setDiskObjectId($entity->getDiskObjectId())
			->setErrorCode($entity->getErrorCode())
			->setOwnerClientId($entity->getOwnerClientId())
			->setOwnerHeartbeatAt($entity->getOwnerHeartbeatAt())
		;

		if ($entity->getCreateTime() !== null)
		{
			$ormModel->setCreateTime($entity->getCreateTime());
		}

		// Always bump UPDATE_TIME on persist so status transitions
		// (fail/retry/cancel/reclassify) refresh the sort/heuristic key,
		// regardless of the (possibly stale) value carried by the entity.
		$ormModel->setUpdateTime(new DateTime());

		return $ormModel;
	}

	private function resolveItemType(string $rawItemType): ItemType
	{
		$itemType = ItemType::tryFrom($rawItemType);
		if ($itemType === null)
		{
			throw new LogicException("Invalid value \"$rawItemType\" for docked upload item type");
		}

		return $itemType;
	}

	private function resolveStatus(string $rawStatus): ItemStatus
	{
		$status = ItemStatus::tryFrom($rawStatus);
		if ($status === null)
		{
			throw new LogicException("Invalid value \"$rawStatus\" for docked upload item status");
		}

		return $status;
	}

	private function resolveConflictPolicy(?string $rawConflictPolicy): ?ConflictPolicy
	{
		if ($rawConflictPolicy === null)
		{
			return null;
		}

		$conflictPolicy = ConflictPolicy::tryFrom($rawConflictPolicy);
		if ($conflictPolicy === null)
		{
			throw new LogicException("Invalid value \"$rawConflictPolicy\" for docked upload conflict policy");
		}

		return $conflictPolicy;
	}

	/**
	 * IntegerField::cast() semantics, which the array read path does not get from the ORM:
	 * a null stays null (every nullable integer column of the table), anything else becomes
	 * an int.
	 */
	private function toNullableInt(mixed $value): ?int
	{
		return $value === null ? null : (int)$value;
	}
}
