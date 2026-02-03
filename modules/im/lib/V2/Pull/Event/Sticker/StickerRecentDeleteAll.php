<?php

namespace Bitrix\Im\V2\Pull\Event\Sticker;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Pull\EventType;

class StickerRecentDeleteAll extends BaseStickerEvent
{
	use ContextCustomer;

	public function __construct()
	{
		parent::__construct();
	}

	protected function getType(): EventType
	{
		return EventType::StickerRecentDeleteAll;
	}

	protected function getBasePullParamsInternal(): array
	{
		return [];
	}

	protected function getRecipients(): array
	{
		return [$this->getContext()->getUserId()];
	}

	public function getTarget(): ?Chat
	{
		return null;
	}
}
