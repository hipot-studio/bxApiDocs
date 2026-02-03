<?php

namespace Bitrix\Im\V2\Recent\Config;

use Bitrix\Im\V2\Message\Counter\CounterType;

class RecentConfig
{
	private ?string $ownSectionName = null;

	public function __construct(
		public readonly bool $useDefaultRecentSection = true,
		public readonly bool $hasOwnRecentSection = false,
		public string $counterType = CounterType::Chat,
	){}

	public function setOwnSectionName(string $name): self
	{
		$this->ownSectionName = $name;

		return $this;
	}

	public function getOwnSectionName(): ?string
	{
		return $this->ownSectionName;
	}

	public function setCounterType(string $counterType): self
	{
		$this->counterType = $counterType;

		return $this;
	}

	public function getCounterType(): string
	{
		return $this->counterType;
	}
}