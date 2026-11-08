<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller\ActionFilter;

use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

/**
 * The shape of a refusal shared by the gates of the mass flows: the denial the subclass names is
 * answered as 403 with its own code, so that a caller tells the conditions apart by the code alone.
 */
abstract class MassAccessBase extends Base
{
	abstract protected function getDenial(): ?Error;

	public function onBeforeAction(Event $event)
	{
		$denial = $this->getDenial();
		if ($denial === null)
		{
			return null;
		}

		Context::getCurrent()->getResponse()->setStatus(403);
		$this->addError($denial);

		return new EventResult(EventResult::ERROR, null, null, $this);
	}
}
