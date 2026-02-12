<?php

namespace Bitrix\Im\V2\Analytics\Event;

class StickerEvent extends Event
{
	protected function getTool(): string
	{
		return 'im';
	}

	protected function getCategory(string $eventName): string
	{
		return 'stickers';
	}
}
