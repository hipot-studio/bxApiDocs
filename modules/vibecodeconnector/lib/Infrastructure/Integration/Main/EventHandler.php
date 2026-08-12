<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Integration\Main;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;
use Bitrix\Vibecodeconnector\Public\Service\AvailabilityService;

final class EventHandler
{
	public static function onEpilog(): void
	{
		if (!Loader::includeModule('vibecodeconnector'))
		{
			return;
		}

		$isAdminSection = defined('ADMIN_SECTION') && ADMIN_SECTION === true;
		if (!$isAdminSection)
		{
			$availability = ServiceLocator::getInstance()->get(AvailabilityService::class);
			if (!$availability->isEnabled())
			{
				return;
			}
		}

		Extension::load('vibecodeconnector.im-button-binder');
	}
}
