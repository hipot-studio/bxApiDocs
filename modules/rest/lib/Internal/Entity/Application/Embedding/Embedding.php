<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Application\Embedding;

use Bitrix\Main;

class Embedding implements Main\Entity\EntityInterface
{
	private ?int $id = null;

	public function __construct(
		private string $placement,
		private string $handler,
		private ?int $appId = null,
		private ?int $userId = null,
		private ?int $iconId = null,
		private ?string $title = null,
		private ?string $groupName = null,
		private ?string $description = null,
		private ?Main\Type\DateTime $dateCreate = null,
		private ?string $additional = null,
		private ?array $options = null,
		private EmbeddingLanguageCollection $languages = new EmbeddingLanguageCollection(),
	)
	{
	}

	public function getAppId(): ?int
	{
		return $this->appId;
	}

	public function setAppId(?int $appId): self
	{
		$this->appId = $appId;

		return $this;
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

	public function getUserId(): ?int
	{
		return $this->userId;
	}

	public function setUserId(?int $userId): self
	{
		$this->userId = $userId;

		return $this;
	}

	public function getPlacement(): ?string
	{
		return $this->placement;
	}

	public function setPlacement(?string $placement): self
	{
		$this->placement = $placement;

		return $this;
	}

	public function getHandler(): ?string
	{
		return $this->handler;
	}

	public function setHandler(?string $handler): self
	{
		$this->handler = $handler;

		return $this;
	}

	public function getIconId(): ?int
	{
		return $this->iconId;
	}

	public function setIconId(?int $iconId): self
	{
		$this->iconId = $iconId;

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

	public function getGroupName(): ?string
	{
		return $this->groupName;
	}

	public function setGroupName(?string $groupName): self
	{
		$this->groupName = $groupName;

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

	public function getDateCreate(): ?Main\Type\DateTime
	{
		return $this->dateCreate;
	}

	public function setDateCreate(?Main\Type\DateTime $dateCreate): self
	{
		$this->dateCreate = $dateCreate;

		return $this;
	}

	public function getAdditional(): ?string
	{
		return $this->additional;
	}

	public function setAdditional(?string $additional): self
	{
		$this->additional = $additional;

		return $this;
	}

	public function getOptions(): ?array
	{
		return $this->options;
	}

	public function setOptions(?array $options): self
	{
		$this->options = $options;

		return $this;
	}

	public function getLanguages(): EmbeddingLanguageCollection
	{
		return $this->languages;
	}

	public function setLanguages(EmbeddingLanguageCollection $languages): self
	{
		$this->languages = $languages;

		return $this;
	}

	public function addLanguage(EmbeddingLanguage $language): self
	{
		$this->languages->add($language);

		return $this;
	}
}
