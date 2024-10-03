<?php

namespace Bitrix\Intranet\MainPage;

use Bitrix\Intranet\Settings\Tools\ToolsManager;

class EventHandler
{
	public static function onLicenseHasChanged(): void
	{
		ToolsManager::getInstance()->getFirstPageChanger()->changeForAllUsers();
	}
}