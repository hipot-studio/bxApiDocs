<?php

namespace Bitrix\BIConnector;

use Bitrix\Main\Config\Option;

/**
 * Holds the time window during which queries of external BI systems are collected into the usage statistics log.
 */
final class ExternalStatLoggerManager
{
	private const END_TIME_OPTION_NAME = 'external_stat_end_time';
	private const LOGGING_DURATION = 86400;
	private const SECONDS_IN_HOUR = 3600;

	public static function getEndTimeLogging(): int
	{
		return (int)Option::get('biconnector', self::END_TIME_OPTION_NAME, 0);
	}

	public static function isActive(): bool
	{
		return self::getEndTimeLogging() > time();
	}

	/**
	 * The only place where the remainder is calculated: a client cannot do it from the window end
	 * without its own clock, and any difference with the server clock rounds the remainder up.
	 *
	 * @return int Hours left until the window closes, rounded up; 0 when the window is closed.
	 */
	public static function getHoursLeft(): int
	{
		$secondsLeft = self::getEndTimeLogging() - time();

		return $secondsLeft > 0 ? (int)ceil($secondsLeft / self::SECONDS_IN_HOUR) : 0;
	}

	public static function enable(): void
	{
		Option::set('biconnector', self::END_TIME_OPTION_NAME, (string)(time() + self::LOGGING_DURATION));
	}

	/**
	 * Moves the end time into the past instead of deleting the option.
	 */
	public static function disable(): void
	{
		Option::set('biconnector', self::END_TIME_OPTION_NAME, '0');
	}
}
