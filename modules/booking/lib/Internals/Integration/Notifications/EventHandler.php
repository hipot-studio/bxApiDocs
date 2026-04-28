<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Notifications;

use Bitrix\Booking\Internals\Container;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;

class EventHandler
{
	public static function onMessageStatusUpdate(Event $event): void
	{
		if (!Loader::includeModule('crm'))
		{
			return;
		}

		Container::getCrmMessageSender()->onMessageStatusUpdate($event);
	}
}
