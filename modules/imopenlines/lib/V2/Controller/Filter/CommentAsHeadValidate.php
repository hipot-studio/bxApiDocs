<?php

namespace Bitrix\ImOpenLines\V2\Controller\Filter;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

class CommentAsHeadValidate extends Base
{
	public function onBeforeAction(Event $event)
	{
		$action = $this->getAction();
		$arguments = $action->getArguments();

		$comment = isset($arguments['comment']) ? trim((string)$arguments['comment']) : '';

		if ($comment === '')
		{
			$this->addError(new Error('Comment must not be empty', 'EMPTY_COMMENT'));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		$arguments['comment'] = $comment;
		$action->setArguments($arguments);

		return null;
	}
}
