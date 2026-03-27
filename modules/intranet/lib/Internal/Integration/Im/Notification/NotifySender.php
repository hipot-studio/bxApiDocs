<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Integration\Im\Notification;

use Bitrix\Main\Loader;

class NotifySender
{
	public static function isAvailable(): bool
	{
		return Loader::includeModule('im');
	}

	public function send(Message $message): int|bool
	{
		if (!self::isAvailable())
		{
			return false;
		}

		return \CIMNotify::Add($message->toArray());
	}
}
