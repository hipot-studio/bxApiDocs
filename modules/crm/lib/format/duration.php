<?php

namespace Bitrix\Crm\Format;

use Bitrix\Main\Localization\Loc;

class Duration
{
	public static function format(int $duration): string
	{
		if ($duration < 0)
		{
			$duration = 0;
		}

		$hours = intdiv($duration, 3600);
		$minutes = intdiv($duration % 3600, 60);
		$seconds = $duration % 60;

		$minutesStr = sprintf('%02d %s', $minutes, Loc::getMessage('DURATION_MIN'));
		$secondsStr = sprintf('%02d %s', $seconds, Loc::getMessage('DURATION_SEC'));

		if ($hours > 0)
		{
			return sprintf(
				'%02d %s %s %s',
				$hours,
				Loc::getMessage('DURATION_HOUR'),
				$minutesStr,
				$secondsStr
			);
		}

		if ($minutes > 0)
		{
			return sprintf('%s %s', $minutesStr, $secondsStr);
		}

		return $secondsStr;
	}
}
