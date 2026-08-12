<?php

namespace Bitrix\Im\V2\Chat\ExternalChat\Event;

class BeforeAttachChildEvent extends ChildChatEvent
{
	protected function getActionName(): string
	{
		return 'BeforeAttachChild';
	}
}
