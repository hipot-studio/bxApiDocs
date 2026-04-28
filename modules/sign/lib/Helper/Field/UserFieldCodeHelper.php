<?php

namespace Bitrix\Sign\Helper\Field;

final class UserFieldCodeHelper
{
	public const PREFIX = 'USER_';

	public static function hasPrefix(string $fieldCode): bool
	{
		return str_starts_with($fieldCode, self::PREFIX);
	}

	public static function removePrefix(string $fieldCode): string
	{
		if (!self::hasPrefix($fieldCode))
		{
			return $fieldCode;
		}

		return mb_substr($fieldCode, mb_strlen(self::PREFIX));
	}

	public static function addPrefix(string $fieldCode): string
	{
		if (self::hasPrefix($fieldCode))
		{
			return $fieldCode;
		}

		return self::PREFIX . $fieldCode;
	}
}
