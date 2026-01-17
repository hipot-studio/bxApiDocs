<?php

namespace Bitrix\Im\V2\Common;

use Bitrix\Main\Engine\Response\Converter;

final class FormatConverter
{
	public static function normalizeToUpperSnakeCase(string $value): string
	{
		if (str_contains($value, '_'))
		{
			return mb_strtoupper($value);
		}

		if (preg_match('/[a-z]/', $value))
		{
			return self::toUpperSnakeCase($value);
		}

		return $value;
	}

	public static function toUpperSnakeCase(string $value): string
	{
		return (new Converter(Converter::TO_SNAKE | Converter::TO_UPPER))->process($value);
	}

	public static function toCamelCase(string $value): string
	{
		return (new Converter(Converter::TO_CAMEL | Converter::LC_FIRST))->process($value);
	}
}
