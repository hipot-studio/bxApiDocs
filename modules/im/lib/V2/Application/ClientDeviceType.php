<?php

namespace Bitrix\Im\V2\Application;

/**
 * The closed list of the client modes of the startup config.
 * Extending it is a new design decision, not a matter of adding a case.
 */
enum ClientDeviceType: string
{
	case BROWSER = 'browser';
	case DESKTOP = 'desktop';

	public function createContext(): Context
	{
		return match ($this)
		{
			self::BROWSER => BrowserContext::getCurrent(),
			self::DESKTOP => DesktopContext::getCurrent(),
		};
	}
}
