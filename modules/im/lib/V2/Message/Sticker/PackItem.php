<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker;

class PackItem
{
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly PackType $type,
		public readonly array $stickers,
	){}

	public function toRestFormat(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'type' => $this->type->value,
			'stickers' => array_values($this->stickers),
		];
	}
}
