<?php

namespace Bitrix\Im\V2\Chat\ExternalChat\Event;

class AfterDetachChildEvent extends ChildChatEvent
{
	protected function getActionName(): string
	{
		return 'AfterDetachChild';
	}
}
