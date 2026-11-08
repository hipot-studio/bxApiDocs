<?php

namespace Bitrix\TimemanMobile\Internal\Integration\Stafftrack\Provider;

use Bitrix\Main\Loader;
use Bitrix\StaffTrack\Public\Provider;

final class CheckInSettingsProvider
{
	public static function isCheckInEnabled(): bool
	{
		return
			Loader::includeModule('stafftrack')
			&& (new Provider\CheckInSettingsProvider())->isCheckInEnabled()
		;
	}
}
