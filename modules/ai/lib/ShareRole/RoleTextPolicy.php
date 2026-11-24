<?php

declare(strict_types=1);

namespace Bitrix\AI\ShareRole;

use Bitrix\AI\Exception\ValidateException;
use Bitrix\Main\Context;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

final class RoleTextPolicy
{
	public const MAX_LENGTH = 10000;

	public static function normalize(string $text): string
	{
		return str_replace("\r\n", "\n", $text);
	}

	public static function length(string $text): int
	{
		return mb_strlen(self::normalize($text), 'UTF-8');
	}

	public static function validate(string $text, string $fieldName): void
	{
		if (self::length($text) > self::MAX_LENGTH)
		{
			$culture = Context::getCurrent()->getCulture();
			$formattedMaxLength = number_format(
				self::MAX_LENGTH,
				0,
				$culture->getNumberDecimalSeparator(),
				$culture->getNumberThousandsSeparator(),
			);

			throw new ValidateException(
				$fieldName,
				Loc::getMessage('AI_ROLE_TEXT_TOO_LONG', ['#MAX_LENGTH#' => $formattedMaxLength]),
			);
		}
	}
}
