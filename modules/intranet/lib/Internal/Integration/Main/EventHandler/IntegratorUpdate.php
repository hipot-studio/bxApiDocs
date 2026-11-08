<?php

namespace Bitrix\Intranet\Internal\Integration\Main\EventHandler;

use Bitrix\Intranet\Internal\Integration\Main\Integrator\IntegratorInfoService;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\ModuleManager;

class IntegratorUpdate
{
	private static bool $updateScheduled = false;

	public static function onAfterIntegratorUpdate($arEvent = null): void
	{
		if (ModuleManager::isModuleInstalled('bitrix24'))
		{
			return;
		}

		if (self::$updateScheduled || !self::hasPartnerChanged())
		{
			return;
		}

		Application::getInstance()->addBackgroundJob(
			static function (): void {
				self::$updateScheduled = false;
				if (!ModuleManager::isModuleInstalled('bitrix24') && self::hasPartnerChanged())
				{
					static::createService()->updateIntegratorInfo();
				}
			},
		);
		self::$updateScheduled = true;
	}

	private static function hasPartnerChanged(): bool
	{
		return Application::getInstance()->getLicense()->getPartnerId()
			!== (int)Option::get('bitrix24', 'partner_id', '')
		;
	}

	protected static function createService(): IntegratorInfoService
	{
		return IntegratorInfoService::createByDefault();
	}
}
