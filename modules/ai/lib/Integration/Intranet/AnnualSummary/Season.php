<?php

declare(strict_types=1);

namespace Bitrix\AI\Integration\Intranet\AnnualSummary;

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
}
