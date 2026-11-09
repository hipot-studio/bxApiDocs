<?php

namespace Bitrix\Sender\Dispatch;

use Bitrix\Main\Type\DateTime;

class DurationFormatter
{
	/**
	 * @param int $interval Interval in seconds.
	 */
	public function formatInterval(int $interval): string
	{
		if ($interval < Duration::getMinimalInterval())
		{
			return Duration::getFormattedMinimalInterval();
		}

		if ($interval > Duration::getMaximalInterval())
		{
			return Duration::getFormattedMaximalInterval();
		}

		$date = new DateTime();
		$date->add($interval . ' seconds');
		$formatted = \FormatDate('Hdiff', $date);
		if (mb_substr($formatted, 0, 1) === '-')
		{
			$formatted = mb_substr($formatted, 1);
		}

		return $formatted;
	}
}
