<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Entity\Catalog;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

final class CatalogItem implements EntityInterface
{
	private ?int $id = null;

	public function __construct(
		private string $title,
		private CatalogItemType $type,
		private CatalogItemAccessType $accessType,
		private int $ownerId,
		private string $color,
		private ?int $iconFileId = null,
		private ?int $chatId = null,
		private ?string $externalId = null,
		private ?string $description = null,
		private ?string $editUrl = null,
		private ?string $viewUrl = null,
		private ?string $pairingIss = null,
		private ?DateTime $deactivatedAt = null,
		private ?DateTime $createdAt = null,
		private ?DateTime $updatedAt = null,
	) {}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function setTitle(string $title): self
	{
		$this->title = $title;

		return $this;
	}

	public function getType(): CatalogItemType
	{
		return $this->type;
	}

	public function getAccessType(): CatalogItemAccessType
	{
		return $this->accessType;
	}

	public function setAccessType(CatalogItemAccessType $accessType): self
	{
		$this->accessType = $accessType;

		return $this;
	}

	public function getOwnerId(): int
	{
		return $this->ownerId;
	}

	public function getColor(): string
	{
		return $this->color;
	}

	public function getChatId(): ?int
	{
		return $this->chatId;
	}

	public function setChatId(?int $chatId): self
	{
		$this->chatId = $chatId;

		return $this;
	}

	public function getExternalId(): ?string
	{
		return $this->externalId;
	}

	public function setExternalId(?string $externalId): self
	{
		$this->externalId = $externalId;

		return $this;
	}

	public function getIconFileId(): ?int
	{
		return $this->iconFileId;
	}

	public function setIconFileId(?int $iconFileId): self
	{
		$this->iconFileId = $iconFileId;

		return $this;
	}

	public function getDescription(): ?string
	{
		return $this->description;
	}

	public function setDescription(?string $description): self
	{
		$this->description = $description;

		return $this;
	}

	public function getEditUrl(): ?string
	{
		return $this->editUrl;
	}

	public function setEditUrl(?string $editUrl): self
	{
		$this->editUrl = $editUrl;

		return $this;
	}

	public function getViewUrl(): ?string
	{
		return $this->viewUrl;
	}

	public function setViewUrl(?string $viewUrl): self
	{
		$this->viewUrl = $viewUrl;

		return $this;
	}

	public function getPairingIss(): ?string
	{
		return $this->pairingIss;
	}

	public function setPairingIss(?string $pairingIss): self
	{
		$this->pairingIss = $pairingIss;

		return $this;
	}

	public function getDeactivatedAt(): ?DateTime
	{
		return $this->deactivatedAt;
	}

	public function setDeactivatedAt(?DateTime $deactivatedAt): self
	{
		$this->deactivatedAt = $deactivatedAt;

		return $this;
	}

	public function isDeactivated(): bool
	{
		return $this->deactivatedAt !== null;
	}

	public function getCreatedAt(): ?DateTime
	{
		return $this->createdAt;
	}

	public function setCreatedAt(DateTime $createdAt): self
	{
		$this->createdAt = $createdAt;

		return $this;
	}

	public function getUpdatedAt(): ?DateTime
	{
		return $this->updatedAt;
	}

	public function setUpdatedAt(DateTime $updatedAt): self
	{
		$this->updatedAt = $updatedAt;

		return $this;
	}
}
