<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Entity\DockedUpload;

use Bitrix\Disk\Internal\Enum\DockedUpload\QueueStatus;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

/**
 * Domain entity for a docked upload session (one user upload queue).
 *
 * A single queue holds items targeting different folders; the target folder
 * is stored per item, not on the queue.
 */
final class DockedUploadQueue implements EntityInterface
{
	protected ?int $id = null;
	protected ?int $userId = null;
	protected ?QueueStatus $status = null;
	protected ?DateTime $createTime = null;
	protected ?DateTime $updateTime = null;
	protected ?DateTime $expiresAt = null;

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(?int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getUserId(): ?int
	{
		return $this->userId;
	}

	public function setUserId(?int $userId): self
	{
		$this->userId = $userId;

		return $this;
	}

	public function getStatus(): ?QueueStatus
	{
		return $this->status;
	}

	public function setStatus(?QueueStatus $status): self
	{
		$this->status = $status;

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

	public function getExpiresAt(): ?DateTime
	{
		return $this->expiresAt;
	}

	public function setExpiresAt(?DateTime $expiresAt): self
	{
		$this->expiresAt = $expiresAt;

		return $this;
	}

	/**
	 * Whether a page reload would bring this queue back (API-02, AC-077).
	 *
	 * The in-memory twin of the row filter in
	 * {@see \Bitrix\Disk\Internal\Repository\DockedUpload\DockedUploadQueueRepository::getActiveByUser()}:
	 * both read the status set from {@see QueueStatus::isRestorable()} and compare
	 * EXPIRES_AT against now. It is what {@see \Bitrix\Disk\Internal\Dto\DockedUpload\QueueDto::$restorable}
	 * reports, so the client never gates a queue on a restorability the server would
	 * then contradict.
	 *
	 * A queue with no EXPIRES_AT is not restorable, exactly as the SQL comparison
	 * against a NULL never matches; the column is NOT NULL, so only an entity that
	 * has never been persisted takes that branch.
	 */
	public function isRestorable(): bool
	{
		return $this->status?->isRestorable() === true
			&& $this->expiresAt !== null
			&& $this->expiresAt->getTimestamp() > time()
		;
	}
}
