<?php

namespace Bitrix\ImOpenLines\V2\Settings;

use Bitrix\Im\Common;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;

Loader::requireModule('im');

class Settings
{
	private const OPTION_CATEGORY = 'imopenlines';
	private const OPTION_NAME = 'v2_activated';

	public static function isV2Available(): bool
	{
		$userId = Common::getUserId();

		$urlFlag = Context::getCurrent()?->getRequest()->getQuery('IMOPENLINES_V2');
		if ($urlFlag === 'Y' || $urlFlag === 'N')
		{
			$enabled = ($urlFlag === 'Y');
			if ($userId !== false)
			{
				self::setV2ActivatedForUser($enabled, (int)$userId);
			}

			return $enabled;
		}

		$globallyActivated = self::isV2Activated();

		if ($userId === false)
		{
			return $globallyActivated;
		}

		$default = $globallyActivated ? 'Y' : 'N';

		return \CUserOptions::GetOption(self::OPTION_CATEGORY, self::OPTION_NAME, $default, $userId) === 'Y';
	}

	public static function isV2Activated(): bool
	{
		return Option::get(self::OPTION_CATEGORY, self::OPTION_NAME, 'N') === 'Y';
	}

	public static function setV2ActivatedForUser(bool $active, int $userId): bool
	{
		$value = $active ? 'Y' : 'N';

		$default = self::isV2Activated() ? 'Y' : 'N';
		$current = \CUserOptions::GetOption(self::OPTION_CATEGORY, self::OPTION_NAME, $default, $userId);
		if ($current === $value)
		{
			return true;
		}

		\CUserOptions::SetOption(self::OPTION_CATEGORY, self::OPTION_NAME, $value, false, $userId);

		if (Loader::includeModule('intranet'))
		{
			\Bitrix\Intranet\Composite\CacheProvider::deleteUserCache();
		}

		return true;
	}
}