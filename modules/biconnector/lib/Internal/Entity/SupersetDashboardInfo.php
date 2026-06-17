<?php

namespace Bitrix\BIConnector\Internal\Entity;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

class SupersetDashboardInfo implements EntityInterface
{
	private ?int $id = null;

	public function __construct(
		private readonly int $dashboardId,
		private ?int $publishedById = null,
		private ?DateTime $publishedDate = null,
		private ?int $updatedById = null,
		private ?DateTime $updatedDate = null,
		private ?string $description = null,
		private ?int $imageId = null,
	)
	{
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(?int $id): self
	{
		$this->id = $id;
		return $this;
	}

	public function getDashboardId(): int
	{
		return $this->dashboardId;
	}

	public function getPublishedById(): ?int
	{
		return $this->publishedById;
	}

	public function setPublishedById(?int $publishedById): self
	{
		$this->publishedById = $publishedById;
		return $this;
	}

	public function getPublishedDate(): ?DateTime
	{
		return $this->publishedDate;
	}

	public function setPublishedDate(?DateTime $publishedDate): self
	{
		$this->publishedDate = $publishedDate;
		return $this;
	}

	public function getUpdatedById(): ?int
	{
		return $this->updatedById;
	}

	public function setUpdatedById(?int $updatedById): self
	{
		$this->updatedById = $updatedById;
		return $this;
	}

	public function getUpdatedDate(): ?DateTime
	{
		return $this->updatedDate;
	}

	public function setUpdatedDate(?DateTime $updatedDate): self
	{
		$this->updatedDate = $updatedDate;
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

	public function getImageId(): ?int
	{
		return $this->imageId;
	}

	public function setImageId(?int $imageId): self
	{
		$this->imageId = $imageId;
		return $this;
	}
}
