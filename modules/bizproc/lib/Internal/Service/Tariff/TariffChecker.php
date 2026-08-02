<?php

namespace Bitrix\Bizproc\Internal\Service\Tariff;

use Bitrix\Main\Loader;
use CBitrix24;

final class TariffChecker
{
	private const BASIC_AND_HIGHER_FAMILIES = ['basic', 'std', 'pro', 'ent'];

	public static function isBasicOrHigher(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return true;
		}

		return in_array(CBitrix24::getLicenseFamily(), self::BASIC_AND_HIGHER_FAMILIES, true);
	}
}
