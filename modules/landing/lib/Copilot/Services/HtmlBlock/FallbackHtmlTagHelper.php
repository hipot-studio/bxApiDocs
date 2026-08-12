<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Services\HtmlBlock;

final class FallbackHtmlTagHelper
{
	public static function appendClass(string $htmlTag, string $class): string
	{
		$class = trim($class);
		if ($htmlTag === '' || $class === '')
		{
			return $htmlTag;
		}

		if (preg_match('/(\sclass\s*=\s*)(["\'])(.*?)\2/iu', $htmlTag, $matches, PREG_OFFSET_CAPTURE))
		{
			$replacement = (string)$matches[1][0]
				. (string)$matches[2][0]
				. self::appendClassToValue((string)$matches[3][0], $class)
				. (string)$matches[2][0]
			;

			return self::replaceMatch($htmlTag, $matches[0], $replacement);
		}

		if (preg_match('/(\sclass\s*=\s*)([^\s>]+)/iu', $htmlTag, $matches, PREG_OFFSET_CAPTURE))
		{
			$replacement = (string)$matches[1][0]
				. '"'
				. self::appendClassToValue((string)$matches[2][0], $class)
				. '"'
			;

			return self::replaceMatch($htmlTag, $matches[0], $replacement);
		}

		return self::insertClassAttribute($htmlTag, $class);
	}

	private static function appendClassToValue(string $value, string $class): string
	{
		$parts = preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY);
		$parts = is_array($parts) ? $parts : [];
		if (!in_array($class, $parts, true))
		{
			$parts[] = $class;
		}

		return implode(' ', $parts);
	}

	private static function insertClassAttribute(string $htmlTag, string $class): string
	{
		if (!preg_match('/^<[a-z][a-z0-9:_-]*/iu', $htmlTag, $matches, PREG_OFFSET_CAPTURE))
		{
			return $htmlTag;
		}

		$offset = (int)$matches[0][1] + strlen((string)$matches[0][0]);

		return substr($htmlTag, 0, $offset)
			. ' class="' . $class . '"'
			. substr($htmlTag, $offset)
		;
	}

	private static function replaceMatch(string $subject, array $match, string $replacement): string
	{
		$value = (string)($match[0] ?? '');
		$offset = (int)($match[1] ?? 0);

		return substr($subject, 0, $offset)
			. $replacement
			. substr($subject, $offset + strlen($value))
		;
	}
}
