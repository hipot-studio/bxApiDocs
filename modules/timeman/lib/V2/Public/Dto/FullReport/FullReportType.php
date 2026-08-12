<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Dto\FullReport;

enum FullReportType: string
{
	case DAY = 'day';
	case WEEK = 'week';
	case MONTH = 'month';
	case NONE = 'none';

	private const SECONDS_IN_DAY = 86400;
	private const DAYS_IN_WEEK = 7;

	public static function resolveByPeriod(?int $dateFrom, ?int $dateTo): ?self
	{
		if ($dateFrom === null || $dateTo === null || $dateTo < $dateFrom)
		{
			return null;
		}

		$daysCount = self::getUtcDayNumber($dateTo) - self::getUtcDayNumber($dateFrom) + 1;

		if ($daysCount <= 1)
		{
			return self::DAY;
		}

		if ($daysCount > self::DAYS_IN_WEEK)
		{
			return self::MONTH;
		}

		return self::WEEK;
	}

	private static function getUtcDayNumber(int $timestamp): int
	{
		return intdiv($timestamp, self::SECONDS_IN_DAY);
	}
}
