<?php

namespace Bitrix\Im\V2\Chat\ExternalChat\Event;

class AfterAttachChildEvent extends ChildChatEvent
{
	protected function getActionName(): string
	{
		return 'AfterAttachChild';
	}
}
