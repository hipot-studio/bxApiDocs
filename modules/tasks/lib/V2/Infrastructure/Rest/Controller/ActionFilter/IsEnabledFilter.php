<?php

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Controller\ActionFilter;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Rest\V3\Exception\AccessDeniedException;

class IsEnabledFilter extends \Bitrix\Tasks\V2\Infrastructure\Controller\ActionFilter\IsEnabledFilter
{
	public function onBeforeAction(Event $event): ?EventResult
	{
		$result = parent::onBeforeAction($event);
		if ($result !== null && $result->getType() === EventResult::ERROR)
		{
			throw new AccessDeniedException();
		}
		return $result;
	}
}