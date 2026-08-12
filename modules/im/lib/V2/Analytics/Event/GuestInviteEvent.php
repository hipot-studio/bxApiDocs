<?php

namespace Bitrix\Im\V2\Analytics\Event;

class GuestInviteEvent extends ChatEvent
{
	protected function getCategory(string $eventName): string
	{
		return 'messenger';
	}
}
