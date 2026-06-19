<?php

namespace Bitrix\ImOpenLines\V2\Controller\Filter;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

class VoteAsHeadValidate extends Base
{
	public function onBeforeAction(Event $event)
	{
		$arguments = $this->getAction()->getArguments();
		$rating = isset($arguments['rating']) ? (int)$arguments['rating'] : null;

		if ($rating === null || $rating < 1 || $rating > 5)
		{
			$this->addError(new Error('Rating must be between 1 and 5', 'INVALID_RATING'));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}
