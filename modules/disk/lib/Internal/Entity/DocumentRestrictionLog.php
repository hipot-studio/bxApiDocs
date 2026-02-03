<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Entity;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

final class DocumentRestrictionLog implements EntityInterface
{
	protected ?int $id = null;
	protected ?string $service = null;
	protected ?int $userId = null;
	protected ?string $externalHash = null;
	protected ?int $status = null;
	protected ?DateTime $createTime = null;
	protected ?DateTime $updateTime = null;

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
	 * @param string|null $service
	 * @return self
	 */
	public function setService(?string $service): self
	{
		$this->service = $service;

		return $this;
	}

	/**
	 * @return string|null
	 */
	public function getService(): ?string
	{
		return $this->service;
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
	 * @param string|null $externalHash
	 * @return self
	 */
	public function setExternalHash(?string $externalHash): self
	{
		$this->externalHash = $externalHash;

		return $this;
	}

	/**
	 * @return string|null
	 */
	public function getExternalHash(): ?string
	{
		return $this->externalHash;
	}

	/**
	 * @param int|null $status
	 * @return self
	 */
	public function setStatus(?int $status): self
	{
		$this->status = $status;

		return $this;
	}

	/**
	 * @return int|null
	 */
	public function getStatus(): ?int
	{
		return $this->status;
	}

	/**
	 * @param DateTime|null $createTime
	 * @return self
	 */
	public function setCreateTime(?DateTime $createTime): self
	{
		$this->createTime = $createTime;

		return $this;
	}

	/**
	 * @return DateTime|null
	 */
	public function getCreateTime(): ?DateTime
	{
		return $this->createTime;
	}

	/**
	 * @param DateTime|null $updateTime
	 * @return self
	 */
	public function setUpdateTime(?DateTime $updateTime): self
	{
		$this->updateTime = $updateTime;

		return $this;
	}

	/**
	 * @return DateTime|null
	 */
	public function getUpdateTime(): ?DateTime
	{
		return $this->updateTime;
	}
}