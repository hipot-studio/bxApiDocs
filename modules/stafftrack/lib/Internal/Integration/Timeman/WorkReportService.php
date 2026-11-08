<?php

namespace Bitrix\StaffTrack\Internal\Integration\Timeman;

use Bitrix\Timeman\V2\Public\Provider\FullReportProvider;

class WorkReportService extends BaseService
{
	public static function hasReportReadyForSubmit(int $userId): bool
	{
		if (!self::isAvailable())
		{
			return false;
		}

		// Mixed-version stands: a timeman build without this class or method degrades to "no report"
		// instead of a fatal inside the mobile tabs hook, so the check runs by class name before new.
		if (!method_exists(FullReportProvider::class, 'getReportToSend'))
		{
			return false;
		}

		return (new FullReportProvider())
			->getReportToSend($userId, true, true)
			->isReadyForSubmit === true
		;
	}
}
