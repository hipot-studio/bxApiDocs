<?php

namespace Bitrix\Rest\V3;

use Bitrix\Main\Context;

class DefaultLanguage
{
	private const DEFAULT_LANGUAGE = 'en';

	public static function get(): string
	{
		$lang = Context::getCurrent()->getLanguage();

		return $lang ?? self::DEFAULT_LANGUAGE;
	}
}
