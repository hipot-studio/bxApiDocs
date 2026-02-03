<?php

namespace Bitrix\Im\V2\Pull\Event\Sticker;

use Bitrix\Im\V2\Message\Sticker\CustomPacks\UserPack;
use Bitrix\Im\V2\Message\Sticker\PackItem;
use Bitrix\Im\V2\Pull\EventType;
use Bitrix\Im\V2\Rest\RestAdapter;

class StickerAdd extends BaseStickerEvent
{
	protected PackItem $pack;

	public function __construct(PackItem $pack)
	{
		parent::__construct();
		$this->pack = $pack;
	}

	protected function getRecipients(): array
	{
		return UserPack::getInstance()->getUsersWithPack($this->pack->id);
	}

	protected function getBasePullParamsInternal(): array
	{
		return (new RestAdapter($this->pack))->toRestFormat();
	}

	protected function getType(): EventType
	{
		return EventType::StickerAdd;
	}
}
