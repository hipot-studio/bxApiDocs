<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker;

class StickerItem
{
	public function __construct(
		public readonly int $id,
		public readonly string $uri,
		public readonly StickerType $type,
		public readonly int $width,
		public readonly int $height,
	){}

	public function toRestFormat(): array
	{
		return [
			'id' => $this->id,
			'uri' => $this->uri,
			'type' => $this->type->value,
			'width' => $this->width,
			'height' => $this->height,
		];
	}
}
