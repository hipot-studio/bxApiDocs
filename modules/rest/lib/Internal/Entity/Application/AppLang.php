<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Application;

use Bitrix\Main\Entity\EntityInterface;

class AppLang implements EntityInterface
{
	private ?int $id = null;

	public function __construct(
		private int $appId,
		private string $languageId,
		private string $menuName,
	)
	{
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getAppId(): int
	{
		return $this->appId;
	}

	public function setAppId(int $appId): self
	{
		$this->appId = $appId;

		return $this;
	}

	public function getLanguageId(): string
	{
		return $this->languageId;
	}

	public function getMenuName(): string
	{
		return $this->menuName;
	}

	public function setMenuName(string $menuName): self
	{
		$this->menuName = $menuName;

		return $this;
	}
}
