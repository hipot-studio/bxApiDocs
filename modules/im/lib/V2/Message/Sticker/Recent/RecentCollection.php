<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker\Recent;

use Bitrix\Im\V2\Message\Sticker\StickerCollection;
use Bitrix\Im\V2\Message\Sticker\StickerService;
use Bitrix\Im\V2\Registry;
use Bitrix\Im\V2\Rest\PopupData;
use Bitrix\Im\V2\Rest\PopupDataAggregatable;
use Bitrix\Im\V2\Rest\RestConvertible;

/**
 * @implements \IteratorAggregate<int,RecentItem>
 *  @method RecentItem offsetGet($key)
 */
class RecentCollection extends Registry implements RestConvertible, PopupDataAggregatable
{
	protected bool $hasMore = true;

	public function getPopupData(array $excludedList = []): PopupData
	{
		return new PopupData([$this->getStickerCollection()], $excludedList);
	}

	public static function getRestEntityName(): string
	{
		return 'recentStickers';
	}

	public function toRestFormat(array $option = []): ?array
	{
		$result = [];

		foreach ($this as $item)
		{
			$result[] = $item->toRestFormat($option);
		}

		return $result;
	}

	public function getStickerCollection(): StickerCollection
	{
		return (new StickerService())->getStickersByRecent($this);
	}
}
