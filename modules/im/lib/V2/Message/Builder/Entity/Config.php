<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity;

class Config implements \JsonSerializable
{
	private function __construct()
	{}

	public static function create(array $builderData): self
	{
		return new self();
	}

	public function jsonSerialize(): array
	{
		return [];
	}

	public static function getRequiredFields(): array
	{
		return [
			Field::Blocks->value,
		];
	}
}
