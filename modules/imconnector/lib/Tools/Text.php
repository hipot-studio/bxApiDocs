<?php
namespace Bitrix\ImConnector\Tools;

class Text
{
	public const QUOTE_SEPARATOR_PATTERN = '/-{6,}/';
	public const QUOTE_SEPARATOR = '----------';

	public static function parseQuoting(string $text = ''): string
	{
		return preg_replace(self::QUOTE_SEPARATOR_PATTERN, self::QUOTE_SEPARATOR, $text);
	}
}
