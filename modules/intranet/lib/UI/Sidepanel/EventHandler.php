<?php

namespace Bitrix\Intranet\UI\Sidepanel;

use \Bitrix\Main;

class EventHandler
{
	public static function onBelowPage()
	{
		global $APPLICATION;

		$APPLICATION->includeComponent('bitrix:intranet.placement', '', []);
		Main\ModuleManager::isModuleInstalled('bitrix24') ?
			$APPLICATION->IncludeComponent('bitrix:bitrix24.notify.panel', 'sidepanel')
			:
			$APPLICATION->IncludeComponent('bitrix:intranet.notify.panel', 'sidepanel')
		;
	}
}
