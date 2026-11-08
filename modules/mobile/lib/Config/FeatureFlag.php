<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Config;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\MobileApp\Mobile;

/**
 * @deprecated Use Bitrix\Main\Config\Feature\AbstractFlag instead
 */
abstract class FeatureFlag
{
	/** Everything a portal option can spell as "off", whoever wrote it. */
	private const OPTION_OFF_VALUES = ['', '0', 'n', 'false'];

	abstract public function isEnabled(): bool;

	abstract public function enable(): void;

	abstract public function disable(): void;

	public function isDisabled(): bool
	{
		return !static::isEnabled();
	}

	/**
	 * Reads a portal option as a flag. The stored form differs between flags - enable() writes 'Y'
	 * in one and a boolean that lands as '1' in another - so the off values are the ones listed and
	 * everything else is on. That way no value meant to switch a flag off can switch it on.
	 */
	protected function isOptionOn(string $moduleId, string $name, bool $default): bool
	{
		$value = mb_strtolower(trim((string)Option::get($moduleId, $name, $default ? 'Y' : 'N')));

		return !in_array($value, self::OPTION_OFF_VALUES, true);
	}

	protected function getCurrentUserId(): ?int
	{
		$id = (int)CurrentUser::get()->getId();

		return $id > 0 ? $id : null;
	}

	protected function clientHasApiVersion(int $apiVersion): bool
	{
		return Mobile::getApiVersion() >= $apiVersion;
	}

	protected function clientUsesDeveloperAppBuild(): bool
	{
		return (bool)Mobile::getInstance()::$isDev;
	}
}
