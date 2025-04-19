<?php

namespace Bitrix\Tasks\Integration\Socialnetwork\Space;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Socialnetwork\Space\Service;

class SpaceService
{
	/**
	 * @throws LoaderException
	 */
	public static function isAvailable(bool $isPublic = false): bool
	{
		if (
			!Loader::includeModule('socialnetwork')
			|| !class_exists(Service::class)
		)
		{
			return false;
		}

		return Service::isAvailable($isPublic);
	}
}
