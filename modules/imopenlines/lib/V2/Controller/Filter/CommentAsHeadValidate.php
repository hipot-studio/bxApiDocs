<?php

namespace Bitrix\ImOpenLines\V2\Controller\Filter;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;

class CommentAsHeadValidate extends Base
{
	public function onBeforeAction(Event $event)
	{
		$action = $this->getAction();
		$arguments = $action->getArguments();

		// empty string is a valid value: it clears the head comment
		$arguments['comment'] = isset($arguments['comment']) ? trim((string)$arguments['comment']) : '';
		$action->setArguments($arguments);

		return null;
	}
}
