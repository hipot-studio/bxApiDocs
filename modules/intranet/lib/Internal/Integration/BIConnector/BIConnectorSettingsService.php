<?php

namespace Bitrix\Intranet\Internal\Integration\BIConnector;

use Bitrix\BIConnector\Configuration\DataTimezone;
use Bitrix\BIConnector\Integration\Superset\CultureFormatter;
use Bitrix\BIConnector\Superset\Import\DashboardReimportService;
use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Loader;

final class BIConnectorSettingsService
{
	/**
	 * Is BI Builder available (by feature and tool)
	 * @return bool
	 */
	public static function isBiBuilderAvailable(): bool
	{
		return
			Loader::includeModule('biconnector')
			&& \Bitrix\BIConnector\Configuration\Feature::isBuilderEnabled()
			&& ToolsManager::getInstance()->checkAvailabilityByToolId('crm_bi')
		;
	}

	/**
	 * Is bi analytics available (data transfering with pbi and gds)
	 * @return bool
	 */
	public static function isBiAnalyticsAvailable(): bool
	{
		return Loader::includeModule('biconnector');
	}

	public static function setLanguage(string $lang): void
	{
		if (!self::isBiBuilderAvailable())
		{
			return;
		}

		CultureFormatter::setLanguageCode($lang);
		DashboardReimportService::runForAllInstalled();
	}

	public static function getCurrentLanguage(): string
	{
		return self::isBiBuilderAvailable() ? CultureFormatter::getLanguageCode() : '';
	}

	public static function getCurrentTimezone(): string
	{
		if (!self::isBiAnalyticsAvailable())
		{
			return '';
		}

		return DataTimezone::getTimezone();
	}

	public static function setTimezone(string $timezone): void
	{
		if (!self::isBiAnalyticsAvailable())
		{
			return;
		}

		DataTimezone::setTimezone($timezone);
	}

	public static function getLanguageList(): array
	{
		if (!self::isBiAnalyticsAvailable())
		{
			return [];
		}

		return CultureFormatter::getLanguageList();
	}
}
