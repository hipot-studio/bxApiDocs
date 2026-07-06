<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Application\Embedding;

use Bitrix\Main;

class EmbeddingLanguage implements Main\Entity\EntityInterface
{
	private ?int $id = null;

	public function __construct(
		private int $embeddingId,
		private string $languageId,
		private ?string $title = null,
		private ?string $description = null,
		private ?string $groupName = null,
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

	public function getEmbeddingId(): int
	{
		return $this->embeddingId;
	}

	public function setEmbeddingId(int $embeddingId): self
	{
		$this->embeddingId = $embeddingId;

		return $this;
	}

	public function getLanguageId(): string
	{
		return $this->languageId;
	}

	public function setLanguageId(string $languageId): self
	{
		$this->languageId = $languageId;

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

	public function getGroupName(): ?string
	{
		return $this->groupName;
	}

	public function setGroupName(?string $groupName): self
	{
		$this->groupName = $groupName;

		return $this;
	}
}
