<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Bitrix24\Admin;

use Bitrix\Main\Loader;

class RestrictionService
{
	public static function isAdminLimitEnabled(): bool
	{
		if (!self::isAvailable())
		{
			return false;
		}

		return \Bitrix\Bitrix24\Public\Service\Admin\RestrictionService::isAdminLimitEnabled();
	}

	public static function isLimitExceeded(): bool
	{
		if (!self::isAvailable())
		{
			return false;
		}

		return \Bitrix\Bitrix24\Public\Service\Admin\RestrictionService::isLimitExceeded();
	}

	private static function isAvailable(): bool
	{
		return Loader::includeModule('bitrix24');
	}
}