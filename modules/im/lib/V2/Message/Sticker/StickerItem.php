<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker;

use Bitrix\Im\V2\Rest\RestConvertible;

class StickerItem implements RestConvertible
{
	public function __construct(
		public readonly int $id,
		public readonly ?string $uri,
		public readonly StickerType $type,
		public readonly int $width,
		public readonly int $height,
		public readonly int $packId,
		public readonly PackType $packType,
		public readonly ?int $fileId = null,
	){}

	public function toRestFormat(array $option = []): ?array
	{
		return [
			'id' => $this->id,
			'uri' => $this->uri,
			'type' => $this->type->value,
			'width' => $this->width,
			'height' => $this->height,
			'packId' => $this->packId,
			'packType' => $this->packType->value,
			'sort' => $this->id,
		];
	}

	public function toShortRestFormat(array $option = []): ?array
	{
		return [
			'id' => $this->id,
			'packId' => $this->packId,
			'packType' => $this->packType->value,
		];
	}

	public static function getRestEntityName(): string
	{
		return 'sticker';
	}

	public function getUniqueKey(): string
	{
		return "{$this->packId}_{$this->packType->value}_{$this->id}";
	}
}
