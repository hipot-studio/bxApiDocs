<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator;

use Bitrix\BIConnector\Superset\Config\ConfigContainer;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

final class ServiceLocation
{
	private const SERVICE_URL_RU = 'https://ss.bitrix24.tech';
	private const SERVICE_URL_DE = 'https://ss-de.bitrix.info';

	public const DATACENTER_LOCATION_REGION_RU = 'ru';
	public const DATACENTER_LOCATION_REGION_EN = 'en';

	private const RU_REGIONS = ['ru', 'by', 'kz', 'uz', 'kg', 'am', 'az', 'ge'];

	public static function getCurrentServiceUrl(): string
	{
		$supersetProxyOption = Option::get('biconnector', 'superset_proxy_url');
		if (!empty($supersetProxyOption))
		{
			return $supersetProxyOption;
		}

		$currentRegion = ConfigContainer::getConfigContainer()->getProxyRegion();
		if (!in_array($currentRegion, [self::DATACENTER_LOCATION_REGION_RU, self::DATACENTER_LOCATION_REGION_EN], true))
		{
			$currentRegion = self::getCurrentDatacenterLocationRegion();
			ConfigContainer::getConfigContainer()->setProxyRegion($currentRegion);
		}

		return self::getServiceUrlByRegion($currentRegion);
	}

	public static function getCurrentDatacenterLocationRegion(): string
	{
		$region = Application::getInstance()->getLicense()->getRegion();
		if (!$region)
		{
			return self::DATACENTER_LOCATION_REGION_RU;
		}

		if (in_array($region, self::RU_REGIONS, true))
		{
			return self::DATACENTER_LOCATION_REGION_RU;
		}

		return self::DATACENTER_LOCATION_REGION_EN;
	}

	public static function isRuRegion(string $region): bool
	{
		return in_array($region, self::RU_REGIONS, true);
	}

	private static function getServiceUrlByRegion(string $region): string
	{
		return match ($region) {
			self::DATACENTER_LOCATION_REGION_EN => self::SERVICE_URL_DE,
			default => self::SERVICE_URL_RU,
		};
	}
}
