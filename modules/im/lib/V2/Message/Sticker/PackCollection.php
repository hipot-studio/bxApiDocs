<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker;

use Bitrix\Im\V2\Registry;
use Bitrix\Im\V2\Rest\PopupData;
use Bitrix\Im\V2\Rest\PopupDataAggregatable;
use Bitrix\Im\V2\Rest\RestConvertible;

/**
 * @implements \IteratorAggregate<int,PackItem>
 *  @method PackItem offsetGet($key)
 */
class PackCollection extends Registry implements RestConvertible, PopupDataAggregatable
{
	protected bool $hasNextPage = true;

	public function getPopupData(array $excludedList = []): PopupData
	{
		return new PopupData([$this->getAllStickers()], $excludedList);
	}

	public static function getRestEntityName(): string
	{
		return 'packs';
	}

	public function toRestFormat(array $option = []): ?array
	{
		$result = [];

		foreach ($this as $pack)
		{
			$result[] = $pack->toRestFormat($option);
		}

		return $result;
	}

	protected function getAllStickers(): StickerCollection
	{
		$stickerCollection = new StickerCollection();

		foreach ($this as $pack)
		{
			foreach ($pack->stickers as $sticker)
			{
				$stickerCollection->offsetSet($sticker->getUniqueKey(), $sticker);
			}
		}

		return $stickerCollection;
	}

	public function hasNextPage(): bool
	{
		return $this->hasNextPage;
	}

	public function setHasNextPage(bool $hasNextPage): self
	{
		$this->hasNextPage = $hasNextPage;
		return $this;
	}
}
