<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker;

use Bitrix\Im\V2\Rest\PopupData;
use Bitrix\Im\V2\Rest\PopupDataAggregatable;
use Bitrix\Im\V2\Rest\RestConvertible;

class PackItem implements RestConvertible, PopupDataAggregatable
{
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly PackType $type,
		public readonly StickerCollection $stickers,
		public readonly ?int $authorId = null,
	){}

	public function toRestFormat(array $option = []): ?array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'type' => $this->type->value,
			'authorId' => $this->authorId,
			'isAdded' => PackFactory::getInstance()->getByType($this->type)->isPackAdded($this->id),
		];
	}

	public static function getRestEntityName(): string
	{
		return 'pack';
	}

	public function getPopupData(array $excludedList = []): PopupData
	{
		return new PopupData([$this->stickers], $excludedList);
	}
}
