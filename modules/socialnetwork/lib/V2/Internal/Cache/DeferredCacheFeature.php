<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Cache;

use Bitrix\Main\Config\Option;

/**
 * Rollout flag for deferred tag cache invalidation. Deliberately absent from socialnetwork/options.php:
 * it switches the moment of invalidation, not a portal setting.
 */
final class DeferredCacheFeature
{
	private const MODULE_ID = 'socialnetwork';
	private const OPTION_NAME = 'deferred_tag_cache_clear';

	public static function isOn(): bool
	{
		return Option::get(self::MODULE_ID, self::OPTION_NAME, 'N') === 'Y';
	}

	public static function turnOn(): void
	{
		Option::set(self::MODULE_ID, self::OPTION_NAME, 'Y');
	}

	public static function turnOff(): void
	{
		Option::set(self::MODULE_ID, self::OPTION_NAME, 'N');
	}
}
