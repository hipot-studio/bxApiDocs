<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Entity;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

final class DocumentSession implements EntityInterface
{
	protected ?int $id = null;
	protected ?DocumentService $service = null;
	protected ?int $objectId = null;
	protected ?int $versionId = null;
	protected ?int $userId = null;
	protected ?int $ownerId = null;
	protected ?bool $isExclusive = null;
	protected ?string $externalHash = null;
	protected ?DateTime $createTime = null;
	protected ?int $type = null;
	protected ?int $status = null;
	protected ?array $context = null;

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
	 * Set service.
	 *
	 * @param DocumentService|null $service
	 * @return self
	 */
	public function setService(?DocumentService $service): self
	{
		$this->service = $service;

		return $this;
	}

	/**
	 * @return DocumentService|null
	 */
	public function getService(): ?DocumentService
	{
		return $this->service;
	}

	/**
	 * @param int|null $objectId
	 * @return self
	 */
	public function setObjectId(?int $objectId): self
	{
		$this->objectId = $objectId;

		return $this;
	}

	/**
	 * @return int|null
	 */
	public function getObjectId(): ?int
	{
		return $this->objectId;
	}

	/**
	 * @param int|null $versionId
	 * @return self
	 */
	public function setVersionId(?int $versionId): self
	{
		$this->versionId = $versionId;

		return $this;
	}

	/**
	 * @return int|null
	 */
	public function getVersionId(): ?int
	{
		return $this->versionId;
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
	 * @param int|null $ownerId
	 * @return self
	 */
	public function setOwnerId(?int $ownerId): self
	{
		$this->ownerId = $ownerId;

		return $this;
	}

	/**
	 * @return int|null
	 */
	public function getOwnerId(): ?int
	{
		return $this->ownerId;
	}

	/**
	 * @param bool|null $isExclusive
	 * @return self
	 */
	public function setIsExclusive(?bool $isExclusive): self
	{
		$this->isExclusive = $isExclusive;

		return $this;
	}

	/**
	 * @return bool|null
	 */
	public function getIsExclusive(): ?bool
	{
		return $this->isExclusive;
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
	 * @param int|null $type
	 * @return self
	 */
	public function setType(?int $type): self
	{
		$this->type = $type;

		return $this;
	}

	/**
	 * @return int|null
	 */
	public function getType(): ?int
	{
		return $this->type;
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
	 * @param array|null $context
	 * @return self
	 */
	public function setContext(?array $context): self
	{
		$this->context = $context;

		return $this;
	}

	/**
	 * @return array|null
	 */
	public function getContext(): ?array
	{
		return $this->context;
	}
}