<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\StaffTrack;

use Bitrix\Main\Loader;
use Bitrix\StaffTrack\Public\Provider\OfficesAvailabilityProvider;

final class EmployeeOfficeCard
{
	public static function isAvailable(): bool
	{
		if (!Loader::includeModule('stafftrack'))
		{
			return false;
		}

		try
		{
			return (new OfficesAvailabilityProvider())->isAvailable();
		}
		catch (\Throwable)
		{
			return false;
		}
	}
}
