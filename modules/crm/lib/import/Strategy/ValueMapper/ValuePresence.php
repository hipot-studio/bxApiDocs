<?php

namespace Bitrix\Crm\Import\Strategy\ValueMapper;

final class ValuePresence
{
	public static function isPresent(mixed $value): bool
	{
		if ($value === null)
		{
			return false;
		}

		if (is_string($value))
		{
			return trim($value) !== '';
		}

		if (is_array($value))
		{
			foreach ($value as $item)
			{
				if (self::isPresent($item))
				{
					return true;
				}
			}

			return false;
		}

		return true;
	}

	public static function normalizeMultipleImportValue(mixed $value, string $delimiter): array
	{
		if (is_array($value))
		{
			$parts = [];
			foreach ($value as $item)
			{
				foreach (self::normalizeMultipleImportValue($item, $delimiter) as $part)
				{
					$parts[] = $part;
				}
			}

			return $parts;
		}

		$parts = [];
		foreach (self::splitMultipleValue((string)$value, $delimiter) as $part)
		{
			$part = trim($part);
			if ($part !== '')
			{
				$parts[] = $part;
			}
		}

		return $parts;
	}

	public static function hasLineBreakDelimiter(mixed $value): bool
	{
		return is_string($value) && preg_match('/<br\s*\/?>|\r|\n/i', $value) === 1;
	}

	private static function splitMultipleValue(string $value, string $delimiter): array
	{
		$value = preg_replace('/<br\s*\/?>/i', "\n", $value);
		$delimiters = array_unique([$delimiter, "\r\n", "\n", "\r"]);
		$patternParts = array_map(static fn(string $delimiter): string => preg_quote($delimiter, '/'), $delimiters);

		return preg_split('/' . implode('|', $patternParts) . '/', $value) ?: [];
	}
}
