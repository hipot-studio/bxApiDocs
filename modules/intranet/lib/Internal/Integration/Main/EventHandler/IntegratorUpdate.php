<?php

namespace Bitrix\Intranet\Internal\Integration\Main\EventHandler;

use Bitrix\Intranet\Internal\Integration\Main\Integrator\IntegratorInfoService;
use Bitrix\Main\ModuleManager;

class IntegratorUpdate
{
	public static function onAfterIntegratorUpdate(&$arEvent): void
	{
		if (ModuleManager::isModuleInstalled('bitrix24'))
		{
			return;
		}

		$service = IntegratorInfoService::createByDefault();
		$service->updateIntegratorInfo();
	}
}
