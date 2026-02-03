<?php

namespace Bitrix\Im\V2\Pull\Event\Sticker;

use Bitrix\Im\V2\Message\Sticker\CustomPacks\StickerUuid;
use Bitrix\Im\V2\Message\Sticker\PackItem;
use Bitrix\Im\V2\Pull\EventType;
use Bitrix\Im\V2\Rest\RestAdapter;

class StickerPackAdd extends BaseStickerEvent
{
	protected PackItem $pack;
	protected array $fileMap;

	public function __construct(PackItem $pack, array $fileMap)
	{
		parent::__construct();
		$this->pack = $pack;
		$this->fileMap = $fileMap;
	}

	protected function getRecipients(): array
	{
		return [$this->getContext()->getUserId()];
	}

	protected function getBasePullParamsInternal(): array
	{
		$stickerUuid = new StickerUuid($this->fileMap, $this->pack->stickers);

		return (new RestAdapter($this->pack, $stickerUuid))->toRestFormat();
	}

	protected function getType(): EventType
	{
		return EventType::StickerPackAdd;
	}
}
