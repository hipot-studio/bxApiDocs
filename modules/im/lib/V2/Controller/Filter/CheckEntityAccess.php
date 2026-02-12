<?php

namespace Bitrix\Im\V2\Controller\Filter;

use Bitrix\Im\V2\AccessCheckable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

/**
 * @see AccessCheckable
 * Prefilter for AccessCheckable entities
 */
class CheckEntityAccess extends Base
{
	public function onBeforeAction(Event $event)
	{
		$checkResult = $this->checkAccess();
		if (!$checkResult->isSuccess())
		{
			$this->addError($checkResult->getErrors()[0] ?? new Chat\ChatError(Chat\ChatError::ACCESS_DENIED));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}

	private function checkAccess(): Result
	{
		foreach ($this->getAction()->getArguments() as $argument)
		{
			if (!$argument instanceof AccessCheckable)
			{
				continue;
			}

			$checkResult = $argument->checkAccess();
			if (!$checkResult->isSuccess())
			{
				return $checkResult;
			}
		}

		return new Result();
	}
}