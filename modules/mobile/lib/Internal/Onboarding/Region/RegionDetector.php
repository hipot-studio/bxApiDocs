<?php

namespace Bitrix\Mobile\Internal\Onboarding\Region;

use Bitrix\Main\Application;
use Bitrix\Mobile\Internal\Onboarding\Region\Regions\BrRegion;
use Bitrix\Mobile\Internal\Onboarding\Region\Regions\ByRegion;
use Bitrix\Mobile\Internal\Onboarding\Region\Regions\CnRegion;
use Bitrix\Mobile\Internal\Onboarding\Region\Regions\DeRegion;
use Bitrix\Mobile\Internal\Onboarding\Region\Regions\EnRegion;
use Bitrix\Mobile\Internal\Onboarding\Region\Regions\EsRegion;
use Bitrix\Mobile\Internal\Onboarding\Region\Regions\FrRegion;
use Bitrix\Mobile\Internal\Onboarding\Region\Regions\ItRegion;
use Bitrix\Mobile\Internal\Onboarding\Region\Regions\JpRegion;
use Bitrix\Mobile\Internal\Onboarding\Region\Regions\KzRegion;
use Bitrix\Mobile\Internal\Onboarding\Region\Regions\PlRegion;
use Bitrix\Mobile\Internal\Onboarding\Region\Regions\RuRegion;
use Bitrix\Mobile\Internal\Onboarding\Region\Regions\TrRegion;
use Bitrix\Mobile\Internal\Onboarding\Region\Regions\VnRegion;

class RegionDetector
{
	/** @var array<string, RegionConfig> */
	private static ?array $regions = null;

	/**
	 * @return array<string, RegionConfig>
	 */
	public static function getRegions(): array
	{
		if (self::$regions === null)
		{
			self::$regions = self::buildRegions();
		}

		return self::$regions;
	}

	/**
	 * @return array<string, RegionConfig>
	 */
	private static function buildRegions(): array
	{
		$regionsMap = [
			RegionCode::RU->value => RuRegion::class,
			RegionCode::BY->value => ByRegion::class,
			RegionCode::KZ->value => KzRegion::class,
			RegionCode::EN->value => EnRegion::class,
			RegionCode::BR->value => BrRegion::class,
			RegionCode::DE->value => DeRegion::class,
			RegionCode::ES->value => EsRegion::class,
			RegionCode::FR->value => FrRegion::class,
			RegionCode::IT->value => ItRegion::class,
			RegionCode::PL->value => PlRegion::class,
			RegionCode::VN->value => VnRegion::class,
			RegionCode::TR->value => TrRegion::class,
			RegionCode::JP->value => JpRegion::class,
			RegionCode::CN->value => CnRegion::class,
		];

		$regionAliases = [
			RegionCode::UZ->value => RegionCode::KZ->value,
		];

		$regions = [];

		foreach ($regionsMap as $code => $regionClass)
		{
			$regionConfig = new RegionConfig($code);
			$region = new $regionClass();
			$region($regionConfig);
			$regions[$code] = $regionConfig;
		}

		foreach ($regionAliases as $alias => $target)
		{
			if (isset($regions[$target]))
			{
				$regions[$alias] = $regions[$target];
			}
		}

		return $regions;
	}

	public function detect(): RegionConfig
	{
		$zone = $this->getPortalZone() ?? RegionCode::EN->value;
		$regions = self::getRegions();

		return clone ($regions[$zone] ?? $regions[RegionCode::EN->value]);
	}

	private function getPortalZone(): ?string
	{
		return Application::getInstance()->getLicense()->getRegion();
	}
}
