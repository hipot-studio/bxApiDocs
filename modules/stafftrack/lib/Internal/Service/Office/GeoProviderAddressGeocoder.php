<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Service\Office;

use Bitrix\Main\Result;
use Bitrix\StaffTrack\Public\Provider\GeoProvider;

class GeoProviderAddressGeocoder implements OfficeAddressGeocoder
{
	private const ADDRESS_MAX_LENGTH = 500;

	public function shortAddress(float $latitude, float $longitude): ?string
	{
		try
		{
			$result = $this->requestAddress($latitude, $longitude);
		}
		catch (\Throwable)
		{
			return null;
		}

		if (!$result->isSuccess())
		{
			return null;
		}

		$address = trim((string)($result->getData()['address'] ?? ''));
		if ($address === '')
		{
			return null;
		}

		return mb_substr($address, 0, self::ADDRESS_MAX_LENGTH);
	}

	protected function requestAddress(float $latitude, float $longitude): Result
	{
		return (new GeoProvider($latitude, $longitude))->generateAddress();
	}
}
