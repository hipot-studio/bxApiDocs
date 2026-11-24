<?php

declare(strict_types=1);

namespace Bitrix\AI\Integration\Intranet\AnnualSummary;

use InvalidArgumentException;

/**
 * Seasons of the annual summary. The year is split into four calendar seasons, so the chat card
 * can compare spring against autumn; winter runs over the new year, not inside it.
 */
final class Season
{
	public const WINTER = 'winter';
	public const SPRING = 'spring';
	public const SUMMER = 'summer';
	public const AUTUMN = 'autumn';

	/**
	 * @return string[]
	 */
	public static function getAll(): array
	{
		return [self::WINTER, self::SPRING, self::SUMMER, self::AUTUMN];
	}

	/**
	 * The season a calendar month belongs to.
	 */
	public static function ofMonth(int $month): string
	{
		return match ($month)
		{
			1, 2, 12 => self::WINTER,
			3, 4, 5 => self::SPRING,
			6, 7, 8 => self::SUMMER,
			9, 10, 11 => self::AUTUMN,
			default => throw new InvalidArgumentException('A month is an int from 1 to 12.'),
		};
	}
}
