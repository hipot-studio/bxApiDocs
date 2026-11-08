<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Common;

final class Normalizer
{
	/**
	 * @param array<int|string|mixed> $values
	 * @return int[]
	 */
	public static function toUniquePositiveIntegers(array $values): array
	{
		$result = [];
		foreach ($values as $value)
		{
			$value = (int)$value;
			if ($value > 0)
			{
				$result[$value] = $value;
			}
		}

		return array_values($result);
	}

	/**
	 * Values that are neither a string nor a number are dropped: casting them would give
	 * the 'Array' literal or throw.
	 *
	 * @param array<string|int|mixed> $values
	 * @return string[] trimmed, non-empty and unique values, each cut to $maxLength
	 */
	public static function toUniqueTrimmedStrings(array $values, int $maxLength): array
	{
		if ($maxLength <= 0)
		{
			throw new \InvalidArgumentException('maxLength must be positive');
		}

		$result = [];
		foreach ($values as $value)
		{
			if (!is_string($value) && !is_numeric($value))
			{
				continue;
			}

			$value = mb_substr(trim((string)$value), 0, $maxLength);
			if ($value !== '')
			{
				$result[$value] = $value;
			}
		}

		return array_values($result);
	}
}
