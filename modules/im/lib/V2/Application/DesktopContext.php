<?php

namespace Bitrix\Im\V2\Application;

/**
 * The application context of the desktop messenger set explicitly by the caller:
 * the desktop signs of the request are not read.
 */
final class DesktopContext extends Context
{
	public function isDesktop(): bool
	{
		return true;
	}
}
