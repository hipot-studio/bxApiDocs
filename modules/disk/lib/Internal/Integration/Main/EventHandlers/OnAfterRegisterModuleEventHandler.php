<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Integration\Main\EventHandlers;

use Bitrix\Disk\Internal\Integration\AiAssistant\Trigger\FileChatTriggerRegistrar;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;

/**
 * Registers the file chat trigger as soon as aiassistant gets installed; in every other case the first
 * file menu registers it.
 */
class OnAfterRegisterModuleEventHandler
{
	public static function handle(Event $event): void
	{
		if (($event->getParameters()[0] ?? null) !== 'aiassistant')
		{
			return;
		}

		// runs inside the aiassistant install, so a failure here must not break it
		try
		{
			if (Loader::includeModule('aiassistant'))
			{
				ServiceLocator::getInstance()->get(FileChatTriggerRegistrar::class)->register();
			}
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}
	}
}
