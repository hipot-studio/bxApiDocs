<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Entity;

use Bitrix\Disk\Internal\Enum\UsageStatus;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

/**
 * Personal storage quota of an employee: the assigned limit in bytes and the last known used size
 * with its actuality status. Sizes are stored in bytes, gigabytes are a unit of the interface only.
 */
final class UserQuota implements EntityInterface
{
	protected ?int $id = null;
	protected ?int $userId = null;
	protected ?int $quotaSize = null;
	protected ?int $usedSize = null;
	protected ?UsageStatus $usedSizeStatus = null;
	protected ?DateTime $usedSizeTime = null;
	protected ?bool $recalcRequested = null;
	protected ?DateTime $statusTime = null;

	/**
	 * @param int|null $id
	 * @return self
	 */
	public function setId(?int $id): self
	{
		$this->id = $id;

		return $this;
	}

	/**
	 * @return int|null
	 */
	public function getId(): ?int
	{
		return $this->id;
	}

	/**
	 * @param int|null $userId
	 * @return self
	 */
	public function setUserId(?int $userId): self
	{
		$this->userId = $userId;

		return $this;
	}

	/**
	 * @return int|null
	 */
	public function getUserId(): ?int
	{
		return $this->userId;
	}

	/**
	 * Set assigned personal limit, bytes.
	 *
	 * @param int|null $quotaSize
	 * @return self
	 */
	public function setQuotaSize(?int $quotaSize): self
	{
		$this->quotaSize = $quotaSize;

		return $this;
	}

	/**
	 * @return int|null
	 */
	public function getQuotaSize(): ?int
	{
		return $this->quotaSize;
	}

	/**
	 * Set last known used size, bytes. Null means the size has never been calculated.
	 *
	 * @param int|null $usedSize
	 * @return self
	 */
	public function setUsedSize(?int $usedSize): self
	{
		$this->usedSize = $usedSize;

		return $this;
	}

	/**
	 * @return int|null
	 */
	public function getUsedSize(): ?int
	{
		return $this->usedSize;
	}

	/**
	 * @param UsageStatus|null $usedSizeStatus
	 * @return self
	 */
	public function setUsedSizeStatus(?UsageStatus $usedSizeStatus): self
	{
		$this->usedSizeStatus = $usedSizeStatus;

		return $this;
	}

	/**
	 * @return UsageStatus|null
	 */
	public function getUsedSizeStatus(): ?UsageStatus
	{
		return $this->usedSizeStatus;
	}

	/**
	 * Set moment of the last successful recalculation.
	 *
	 * @param DateTime|null $usedSizeTime
	 * @return self
	 */
	public function setUsedSizeTime(?DateTime $usedSizeTime): self
	{
		$this->usedSizeTime = $usedSizeTime;

		return $this;
	}

	/**
	 * @return DateTime|null
	 */
	public function getUsedSizeTime(): ?DateTime
	{
		return $this->usedSizeTime;
	}

	/**
	 * Set the mark of an invalidation that arrived while a recalculation run was holding the row.
	 *
	 * @param bool|null $recalcRequested
	 * @return self
	 */
	public function setRecalcRequested(?bool $recalcRequested): self
	{
		$this->recalcRequested = $recalcRequested;

		return $this;
	}

	/**
	 * @return bool|null
	 */
	public function getRecalcRequested(): ?bool
	{
		return $this->recalcRequested;
	}

	/**
	 * Set moment of the last status change, or of the row creation for a just inserted row.
	 *
	 * @param DateTime|null $statusTime
	 * @return self
	 */
	public function setStatusTime(?DateTime $statusTime): self
	{
		$this->statusTime = $statusTime;

		return $this;
	}

	/**
	 * @return DateTime|null
	 */
	public function getStatusTime(): ?DateTime
	{
		return $this->statusTime;
	}
}
