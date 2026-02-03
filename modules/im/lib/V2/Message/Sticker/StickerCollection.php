<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker;

use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Registry;
use Bitrix\Im\V2\Rest\PopupDataItem;

/**
 * @implements \IteratorAggregate<int,StickerItem>
 *  @method null|StickerItem offsetGet($key)
 */
class StickerCollection extends Registry implements PopupDataItem
{
	public function toRestFormat(array $option = []): ?array
	{
		$result = [];

		foreach ($this as $sticker)
		{
			$result[] = $sticker->toRestFormat($option);
		}

		return $result;
	}

	public function getFileIds(): array
	{
		$fileIds = [];

		foreach ($this as $sticker)
		{
			$fileIds[] = $sticker->fileId;
		}

		return $fileIds;
	}

	public function getIds(): array
	{
		$ids = [];

		foreach ($this as $sticker)
		{
			$ids[] = $sticker->id;
		}

		return $ids;
	}

	public static function getRestEntityName(): string
	{
		return 'stickers';
	}

	public function merge(PopupDataItem $item): PopupDataItem
	{
		if ($item instanceof StickerCollection)
		{
			foreach ($item as $sticker)
			{
				$this->offsetSet($sticker->getUniqueKey(), $sticker);
			}
		}

		return $this;
	}

	public static function createByStickers(array $stickerItems): StickerCollection
	{
		$stickerCollection = new self();

		foreach ($stickerItems as $sticker)
		{
			if ($sticker instanceof StickerItem)
			{
				$stickerCollection->offsetSet($sticker->getUniqueKey(), $sticker);
			}
		}

		return $stickerCollection;
	}

	public static function createByMessages(MessageCollection $messages): StickerCollection
	{
		$messages->fillParams();

		$stickers = new StickerCollection();

		foreach ($messages as $message)
		{
			$sticker = $message->getSticker();
			if ($sticker !== null)
			{
				$stickers->offsetSet($sticker->getUniqueKey(), $sticker);
			}
		}

		return $stickers;
	}
}
