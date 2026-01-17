<?php

namespace Bitrix\Tasks\V2\Internal\Service\Time\Trait;

trait FormatElapsedTimeTrait
{
	protected function formatElapsedTime(int $seconds): string
	{
		if ($seconds < 60)
		{
			return \FormatDate('sdiff', time() - $seconds, time());
		}

		$hours = (int)floor($seconds / 3600);
		$minutes = (int)floor(($seconds % 3600) / 60);

		$now = time();
		$timestamp = $now - $seconds;

		if ($hours > 0 && $minutes === 0)
		{
			return \FormatDate('Hdiff', $timestamp, $now);
		}

		if ($hours > 0)
		{
			$hoursFormatted = \FormatDate('Hdiff', $now - ($hours * 3600), $now);
			$minutesFormatted = \FormatDate('idiff', $now - ($minutes * 60), $now);

			return $hoursFormatted . ' ' . $minutesFormatted;
		}

		return \FormatDate('idiff', $timestamp, $now);
	}
}
