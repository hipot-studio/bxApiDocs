<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\StorageType;

use Bitrix\Bizproc\Internal\Entity\EntityInterface;

class StorageType implements EntityInterface
{
	private ?int $id = null;
	private ?string $title = null;
	private ?string $description = null;
	private ?string $code = null;
	private ?int $createdBy = null;
	private ?int $updatedBy = null;
	private ?int $createdAt = null;
	private ?int $updatedAt = null;

	public function getId(): ?int
	{
		return $this->id;
	}

	public function isNew(): bool
	{
		return $this->id === null;
	}

	public function setId(?int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getTitle(): ?string
	{
		return $this->title;
	}

	public function setTitle(?string $title): self
	{
		$this->title = $title;

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

	public function getCode(): ?string
	{
		return $this->code;
	}

	public function setCode(?string $code): self
	{
		$this->code = $code;

		return $this;
	}

	public function getCreatedBy(): ?int
	{
		return $this->createdBy;
	}

	public function setCreatedBy(?int $createdBy): self
	{
		$this->createdBy = $createdBy;

		return $this;
	}

	public function getUpdatedBy(): ?int
	{
		return $this->updatedBy;
	}

	public function setUpdatedBy(?int $updatedBy): self
	{
		$this->updatedBy = $updatedBy;

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

	public function getUpdatedAt(): ?int
	{
		return $this->updatedAt;
	}

	public function setUpdatedAt(?int $updatedAt): self
	{
		$this->updatedAt = $updatedAt;

		return $this;
	}

	public static function mapFromArray(array $props): static
	{
		$result = new self();

		if (isset($props['id']))
		{
			$result->setId((int)$props['id']);
		}

		if (isset($props['title']))
		{
			$result->setTitle((string)$props['title']);
		}

		if (isset($props['description']))
		{
			$result->setDescription((string)$props['description']);
		}

		if (isset($props['code']))
		{
			$result->setCode((string)$props['code']);
		}

		if (isset($props['createdBy']))
		{
			$result->setCreatedBy((int)$props['createdBy']);
		}

		if (isset($props['createdAt']))
		{
			$result->setCreatedAt((int)$props['createdAt']);
		}

		if (isset($props['updatedBy']))
		{
			$result->setUpdatedBy((int)$props['updatedBy']);
		}

		if (isset($props['updatedAt']))
		{
			$result->setUpdatedAt((int)$props['updatedAt']);
		}

		return $result;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'title' => $this->title,
			'description' => $this->description,
			'code' => $this->code,
			'createdBy' => $this->createdBy,
			'updatedBy' => $this->updatedBy,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
		];
	}
}
