<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity;

class Config implements \JsonSerializable
{
	protected ?string $background;

	private function __construct(array $configData)
	{
		$this->background = $configData[Field::Background->value] ?? null;
	}

	public static function create(array $configData): self
	{
		return new self($configData);
	}

	public function jsonSerialize(): array
	{
		return [
			Field::Background->value => $this->background,
		];
	}

	public function toArray(): array
	{
		return [
			Field::Background->value => $this->background,
		];
	}
}
