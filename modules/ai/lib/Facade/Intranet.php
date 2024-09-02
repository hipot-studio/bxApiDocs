<?php

namespace Bitrix\AI\Facade;

use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use CIntranetUtils;

class Intranet
{
	/**
	 * @return bool
	 *
	 * @throws LoaderException
	 */
	public static function isWestZone(): bool
	{
		$zone = self::getPortalZone();

		return $zone !== 'ru' && $zone !== 'by' && $zone !== 'kz';
	}

	/**
	 * @return string
	 *
	 * @throws LoaderException
	 */
	public static function getPortalZone(): string
	{
		if (Loader::includeModule('intranet'))
		{
			return CIntranetUtils::getPortalZone();
		}

		return 'ru';
	}
}
