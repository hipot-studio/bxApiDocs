<?php

namespace Bitrix\Rest\V3\Controller\ActionFilter;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Rest\V3\Exception\AccessDeniedException;

class UserCanDoOperation extends \Bitrix\Rest\Engine\ActionFilter\UserCanDoOperation
{
	public function onBeforeAction(Event $event)
	{
		global $USER;

		foreach ($this->operations as $operation)
		{
			if (!$USER->CanDoOperation($operation))
			{
				throw new AccessDeniedException();
			}
		}

		return new EventResult(EventResult::SUCCESS, null, null, $this);
	}
}
