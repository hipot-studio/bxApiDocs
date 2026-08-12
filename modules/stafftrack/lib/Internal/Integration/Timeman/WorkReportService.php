<?php

namespace Bitrix\StaffTrack\Internal\Integration\Timeman;

use Bitrix\Timeman\V2\Public\Provider\SettingsProvider;

class WorkReportService extends BaseService
{
	public static function enableWorkReportOption(): void
	{
		if (self::isAvailable())
		{
			(new SettingsProvider())->enableReports();
		}
	}

	public static function isPlanningServiceEnabled(): bool
	{
		if (!self::isAvailable())
		{
			return false;
		}

		return (new SettingsProvider())->isPlanningServiceEnabled();
	}
}
