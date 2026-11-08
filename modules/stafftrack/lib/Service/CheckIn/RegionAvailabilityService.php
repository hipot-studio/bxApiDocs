<?php

namespace Bitrix\StaffTrack\Service\CheckIn;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

class RegionAvailabilityService
{
	private const ALLOWED_REGIONS = [
		'com', 'en',
		'ru', 'by', 'kz', 'uz', 'kg', 'am', 'az', 'ge', 'ua',
		'br', 'in', 'cn', 'tr', 'mx', 'co',
		'vn', 'id', 'th', 'ms', 'jp',
		'sc', 'tc', 'hi', 'ur',
		'ae', 'ar', 'md',
	];

	/**
	 * Zones for which geodata does NOT slow down automatic deletion (it is stored indefinitely).
	 * Differs from ALLOWED_REGIONS: the US zones (com, en), Latin America (mx, co) and Brazil (br)
	 * receive geo-features, but their data is deleted.
	 */
	private const RETENTION_ALLOWED_REGIONS = [
		'ru', 'by', 'kz', 'uz', 'kg', 'am', 'az', 'ge', 'ua',
		'in', 'cn', 'tr',
		'vn', 'id', 'th', 'ms', 'jp',
		'sc', 'tc', 'hi', 'ur',
		'ae', 'ar', 'md',
	];

	private const COUNTRY_RESOLVED_REGIONS = ['la'];

	private const RESTRICTED_COUNTRIES = [
		'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
		'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
		'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
		'AX', 'GF', 'GP', 'MF', 'MQ', 'RE', 'YT',
		'IC', 'EA', 'FX',
		'NO', 'IS', 'LI',
		'GB', 'GI', 'UK',
		'CH', 'MC', 'SM', 'AD', 'VA',
	];

	public function isGeoFeaturesAvailableByRegion(): bool
	{
		if (!$this->isCloud())
		{
			return true;
		}

		$region = $this->getPortalRegion();

		if (in_array($region, self::ALLOWED_REGIONS, true))
		{
			return true;
		}

		if (!in_array($region, self::COUNTRY_RESOLVED_REGIONS, true))
		{
			return false;
		}

		$country = $this->getRegistrationCountry();

		return $country !== '' && !in_array($country, self::RESTRICTED_COUNTRIES, true);
	}

	public function isGeoRetentionLimited(): bool
	{
		return !in_array($this->getLicenseRegion(), self::RETENTION_ALLOWED_REGIONS, true);
	}

	protected function getLicenseRegion(): string
	{
		return Application::getInstance()->getLicense()->getRegion() ?? 'en';
	}

	protected function isCloud(): bool
	{
		return Loader::includeModule('bitrix24');
	}

	protected function getPortalRegion(): string
	{
		return \CBitrix24::getPortalZone();
	}

	protected function getRegistrationCountry(): string
	{
		return mb_strtoupper(Option::get('bitrix24', 'REG_COUNTRY', ''));
	}
}
