<?php

namespace Bitrix\Intranet\Internal\Integration\BIConnector;

use Bitrix\BIConnector\Integration\Superset\CultureFormatter;
use Bitrix\BIConnector\Superset\Import\DashboardReimportService;
use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Loader;

final class BIConnectorSettingsService
{
	public static function isAvailable(): bool
	{
		return
			Loader::includeModule('biconnector')
			&& \Bitrix\BIConnector\Configuration\Feature::isBuilderEnabled()
			&& ToolsManager::getInstance()->checkAvailabilityByToolId('crm_bi')
		;
	}

	public static function setLanguage(string $lang): void
	{
		if (!self::isAvailable())
		{
			return;
		}

		CultureFormatter::setLanguageCode($lang);
		DashboardReimportService::runForAllInstalled();
	}

	public static function getCurrentLanguage(): string
	{
		return self::isAvailable() ? CultureFormatter::getLanguageCode() : '';
	}
}

