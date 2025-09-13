<?php

declare(strict_types=1);

namespace Bitrix\Booking\Entity\Resource;

use Bitrix\Booking\Entity\EntityInterface;
use Bitrix\Booking\Entity\Resource\ResourceLinkedEntityData\ResourceLinkedEntityDataInterface;
use Bitrix\Booking\Entity\Resource\ResourceLinkedEntityData\ResourceLinkedEntityDataMapper;
use Bitrix\Booking\Internals\Model\Enum\ResourceLinkedEntityType;

class ResourceLinkedEntity implements EntityInterface
{
	private int|null $id = null;
	private int|null $entityId = null;
	private ResourceLinkedEntityType|null $entityType = null;
	private int|null $createdAt = null;
	private ResourceLinkedEntityDataInterface|null $data = null;

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(?int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getEntityId(): ?int
	{
		return $this->entityId;
	}

	public function setEntityId(?int $entityId): self
	{
		$this->entityId = $entityId;

		return $this;
	}

	public function getEntityType(): ?ResourceLinkedEntityType
	{
		return $this->entityType;
	}

	public function setEntityType(?ResourceLinkedEntityType $entityType): self
	{
		$this->entityType = $entityType;

		return $this;
	}

	public function getCreatedAt(): ?int
	{
		return $this->createdAt;
	}

	public function setCreatedAt(?int $createdAt): self
	{
		$this->createdAt = $createdAt;

		return $this;
	}

	public function getData(): ?ResourceLinkedEntityDataInterface
	{
		return $this->data;
	}

	public function setData(?ResourceLinkedEntityDataInterface $data): self
	{
		$this->data = $data;

		return $this;
	}

	public static function mapFromArray(array $props): ResourceLinkedEntity
	{
		$entityType = $props['entityType'] ? ResourceLinkedEntityType::from($props['entityType']) : null;

		return (new self())
			->setId($props['id'] ?? null)
			->setEntityId($props['entityId'] ?? null)
			->setEntityType($entityType)
			->setCreatedAt($props['createdAt'] ?? null)
//			->setData(
//				($props['data'] ?? null) && $entityType
//					? (new ResourceLinkedEntityDataMapper())->mapFromArray($entityType, $props['data'])
//					: null
//			)
			->setData(null)
		;
	}

	public function toArray(): array
	{
		return [
			'entityId' => $this->entityId,
			'entityType' => $this->entityType->value,
		];
	}
}
