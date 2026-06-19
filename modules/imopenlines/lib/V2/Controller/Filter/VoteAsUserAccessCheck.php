<?php

namespace Bitrix\ImOpenLines\V2\Controller\Filter;

use Bitrix\ImOpenLines\V2\Session\Session;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

class VoteAsUserAccessCheck extends Base
{
	public function onBeforeAction(Event $event): ?EventResult
	{
		foreach ($this->getAction()->getArguments() as $argument)
		{
			if ($argument instanceof Session)
			{
				$currentUserId = (int)CurrentUser::get()->getId();
				if ($argument->getUserId() !== $currentUserId)
				{
					$this->addError(new Error('Access denied', 'ACCESS_DENIED'));

					return new EventResult(EventResult::ERROR, null, null, $this);
				}

				return null;
			}
		}

		return null;
	}
}
