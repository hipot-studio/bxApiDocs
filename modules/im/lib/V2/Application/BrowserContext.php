<?php

namespace Bitrix\Im\V2\Application;

/**
 * The application context of the browser messenger set explicitly by the caller:
 * the desktop signs of the request (User-Agent, BXD_API_VERSION) are not read.
 */
final class BrowserContext extends Context
{
	public function isDesktop(): bool
	{
		return false;
	}
}
