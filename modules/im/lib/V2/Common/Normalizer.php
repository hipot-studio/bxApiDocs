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
	 * A boolean the way it arrives over the wire. The web client encodes booleans as Y/N
	 * (im.v2.lib.rest), REST clients send JSON booleans, form-encoded ones send 1/0.
	 *
	 * @return bool|null null for a value that carries no boolean, an absent field included
	 */
	public static function toNullableBool(mixed $value): ?bool
	{
		if (!is_scalar($value) || $value === '')
		{
			return null;
		}

		return match ($value)
		{
			'Y' => true,
			'N' => false,
			default => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
		};
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
