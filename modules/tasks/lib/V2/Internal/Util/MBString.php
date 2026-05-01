<?php

namespace Bitrix\Tasks\V2\Internal\Util;

class MBString
{
	// @todo Replace with mb_ucfirst() after switching to PHP 8.4
	public static function ucfirst(string $string, ?string $encoding = null): string
	{
		$firstChar = mb_substr($string, 0, 1, $encoding);
		$firstChar = mb_convert_case($firstChar, MB_CASE_TITLE, $encoding);

		return $firstChar . mb_substr($string, 1, null, $encoding);
	}
}
