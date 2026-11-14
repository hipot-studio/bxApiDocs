<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Integration\HumanResources;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Event;

final class EventHandler
{
	public static function onMemberChanged(Event $event): void
	{
		ServiceLocator::getInstance()->get(MemberChangeHandler::class)->handle($event);
	}
}
