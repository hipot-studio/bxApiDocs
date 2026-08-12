<?php

namespace Bitrix\StaffTrack\Service\CheckIn;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

class RegionAvailabilityService
{
	private const ALLOWED_REGIONS = [
		'ru', 'by', 'kz', 'uz', 'kg', 'am', 'az', 'ge', 'ua',
		'br', 'in', 'cn',
	];

	private const GLOBAL_REGIONS = ['com', 'en', 'la'];

	private const ALLOWED_COUNTRIES = [
		'US', 'CA', 'AU', 'NZ',
		'RU', 'BY', 'KZ', 'UZ', 'KG', 'AM', 'AZ', 'GE', 'UA',
		'IN', 'CN',
		// lat am
		'BR', 'MX', 'AR', 'CL', 'CO', 'PE', 'VE', 'EC', 'BO', 'PY',
		'UY', 'CR', 'PA', 'GT', 'HN', 'SV', 'NI', 'DO', 'CU', 'PR',
	];

	private const RESTRICTED_COUNTRIES = [
		'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
		'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
		'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
		'NO', 'IS', 'LI', 'GB', 'CH',
	];

	public function isGeoFeaturesAvailableByRegion(): bool
	{
		if (!$this->isCloud())
		{
			return true;
		}

		$region = $this->getPortalRegion();
		$country = $this->getRegistrationCountry();

		if (in_array($country, self::RESTRICTED_COUNTRIES, true))
		{
			return false;
		}

		if (in_array($region, self::ALLOWED_REGIONS, true))
		{
			return true;
		}

		if (in_array($region, self::GLOBAL_REGIONS, true))
		{
			return in_array($country, self::ALLOWED_COUNTRIES, true);
		}

		return false;
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
