<?php

namespace Bitrix\Im\V2\Pull\Event\Sticker;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Message\Sticker\StickerPacks;
use Bitrix\Im\V2\Message\Sticker\PackFactory;
use Bitrix\Im\V2\Message\Sticker\PackType;
use Bitrix\Im\V2\Pull\BaseEvent;

abstract class BaseStickerEvent extends BaseEvent
{
	use ContextCustomer;

	protected StickerPacks $customPacks;

	public function __construct()
	{
		parent::__construct();
		$this->customPacks = PackFactory::getInstance()->getByType(PackType::Custom);
	}

	public function getTarget(): ?Chat
	{
		return null;
	}
}
