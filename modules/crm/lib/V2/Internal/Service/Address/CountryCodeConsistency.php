<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Address;

use Bitrix\Crm\EntityAddress;

final class CountryCodeConsistency
{
	public const MISMATCH_MESSAGE = 'The code of the country does not hold up against the country of the address.';

	/**
	 * @param array<string, mixed> $addressFields
	 * @return array<string, mixed>
	 */
	public static function clearInconsistent(array $addressFields): array
	{
		$countryCode = $addressFields['COUNTRY_CODE'] ?? '';
		if ($countryCode === '')
		{
			return $addressFields;
		}

		if (!is_array(self::findCountryOfCode($countryCode, $addressFields)))
		{
			$addressFields['COUNTRY_CODE'] = '';
		}

		return $addressFields;
	}

	/** @param array<string, mixed> $addressFields */
	public static function isRefused(array $addressFields): bool
	{
		$countryCode = $addressFields['COUNTRY_CODE'] ?? '';
		if ($countryCode === '')
		{
			return false;
		}

		return !is_array(self::findCountryOfCode($countryCode, $addressFields))
			&& EntityAddress::getCountries() !== []
		;
	}

	/**
	 * @param array<string, mixed> $addressFields
	 * @return array<string, mixed>|null
	 */
	private static function findCountryOfCode(string $countryCode, array $addressFields): ?array
	{
		$country = $addressFields['COUNTRY'] ?? '';
		if ($country === '')
		{
			return null;
		}

		$knownCountry = EntityAddress::getCountryByCode($countryCode);

		return is_array($knownCountry) && ($knownCountry['CAPTION'] ?? null) === $country
			? $knownCountry
			: null
		;
	}
}
