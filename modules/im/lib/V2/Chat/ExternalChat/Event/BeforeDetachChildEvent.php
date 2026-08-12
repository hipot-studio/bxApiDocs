<?php

namespace Bitrix\Im\V2\Chat\ExternalChat\Event;

class BeforeDetachChildEvent extends ChildChatEvent
{
	protected function getActionName(): string
	{
		return 'BeforeDetachChild';
	}
}
