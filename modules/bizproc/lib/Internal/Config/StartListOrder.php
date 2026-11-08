<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Config;

use Bitrix\Main\Config\Option;

/**
 * The only place where the options of the start list order are read: the order of the list, the
 * statistics of successful manual starts and its cleanup are all derived from these getters.
 *
 * The flag bounds the new order alone. Collecting the statistics, cleaning it up and the strict
 * permission check of the list stay on whatever the flag says, so switching the flag on gives a portal
 * the order over data it has already accumulated.
 */
final class StartListOrder
{
	private const MODULE_ID = 'bizproc';

	private const ENABLED_OPTION = 'start_list_order_enabled';
	private const NOVELTY_HOURS_OPTION = 'start_list_novelty_hours';
	private const POPULARITY_DAYS_OPTION = 'start_list_popularity_days';
	private const STAT_RETENTION_DAYS_OPTION = 'start_list_stat_retention_days';

	private const DEFAULT_NOVELTY_HOURS = 168;
	private const DEFAULT_POPULARITY_DAYS = 30;
	private const DEFAULT_STAT_RETENTION_DAYS = 35;

	public static function isEnabled(): bool
	{
		return Option::get(self::MODULE_ID, self::ENABLED_OPTION, 'N') === 'Y';
	}

	/**
	 * How long a template published for the first time stays in the group of the new ones.
	 */
	public static function getNoveltyHours(): int
	{
		return self::getPositiveInt(self::NOVELTY_HOURS_OPTION, self::DEFAULT_NOVELTY_HOURS);
	}

	/**
	 * Width of the window the popularity of a template is summed over, in portal days.
	 */
	public static function getPopularityDays(): int
	{
		return self::getPositiveInt(self::POPULARITY_DAYS_OPTION, self::DEFAULT_POPULARITY_DAYS);
	}

	/**
	 * Days a daily bucket is kept as configured; {@see self::getEffectiveRetentionDays()} is what
	 * both the counter and the cleanup actually obey.
	 */
	public static function getStatRetentionDays(): int
	{
		return self::getPositiveInt(self::STAT_RETENTION_DAYS_OPTION, self::DEFAULT_STAT_RETENTION_DAYS);
	}

	/**
	 * A retention shorter than the popularity window would delete the very days the window still reads,
	 * so the window always wins over the configured retention.
	 */
	public static function getEffectiveRetentionDays(): int
	{
		return max(self::getStatRetentionDays(), self::getPopularityDays());
	}

	/**
	 * A non-numeric override or a value below one would turn a window off instead of narrowing it, so
	 * such values fall back to the default instead of being clamped.
	 */
	private static function getPositiveInt(string $option, int $default): int
	{
		$stored = Option::get(self::MODULE_ID, $option, (string)$default);
		if (!is_numeric($stored))
		{
			return $default;
		}

		$value = (int)$stored;

		return $value >= 1 ? $value : $default;
	}
}
