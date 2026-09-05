<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Filters;

use Bitrix\Disk\Document\Vibeoffice\VibeofficeHandler;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

class VibeofficeEnabled extends ActionFilter\Base
{
	public function onBeforeAction(Event $event)
	{
		if (!VibeofficeHandler::isEnabled())
		{
			$this->addError(new Error('Vibeoffice handler is not configured and not enabled.'));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}
