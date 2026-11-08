<?php

declare(strict_types=1);

namespace Bitrix\Mail\Agent;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Service\ScheduledMessage\ScheduledMessageDispatcher;
use Bitrix\Main\Loader;

final class ScheduledMessageAgent
{
	public static function execute(): string
	{
		if (Loader::includeModule('mail') && Feature::isScheduledMessageAvailable())
		{
			(new ScheduledMessageDispatcher())->dispatch();
		}

		return self::class . '::execute();';
	}
}
