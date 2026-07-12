<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper\Util;

use CTextParser;

class TextFormatter
{
	public static function stripTags(?string $text): ?string
	{
		if ($text === null || $text === '')
		{
			return $text;
		}

		return (string)CTextParser::clearAllTags($text);
	}

	public static function truncate(?string $value, int $maxLength): ?string
	{
		if ($value === null || mb_strlen($value) <= $maxLength)
		{
			return $value;
		}

		return mb_substr($value, 0, $maxLength) . '...';
	}
}
