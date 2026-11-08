<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\StaffTrack;

use Bitrix\StaffTrack\Public\Provider\EmployeeLocationBlockProvider;

final class EmployeeLocationBlock
{
	public static function isAvailable(int $viewerId, int $employeeId): bool
	{
		if (!EmployeeOfficeCard::isAvailable())
		{
			return false;
		}

		try
		{
			return (new EmployeeLocationBlockProvider())->hasBlock($viewerId, $employeeId);
		}
		catch (\Throwable)
		{
			return false;
		}
	}
}
