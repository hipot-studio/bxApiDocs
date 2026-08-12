<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Controller\Request\Common;

final class ScalarCaster
{
	public static function toPositiveInt(mixed $value): ?int
	{
		if (!is_numeric($value))
		{
			return null;
		}

		$intValue = (int)$value;

		return $intValue > 0 ? $intValue : null;
	}

	public static function toNonNegativeInt(mixed $value): ?int
	{
		if (!is_numeric($value))
		{
			return null;
		}

		$intValue = (int)$value;

		return $intValue >= 0 ? $intValue : null;
	}

	/**
	 * @return array<int>|null
	 */
	public static function toPositiveIntCollection(mixed $value): ?array
	{
		if (!is_array($value))
		{
			return null;
		}

		$result = [];
		foreach ($value as $item)
		{
			$id = self::toPositiveInt($item);
			if ($id !== null)
			{
				$result[] = $id;
			}
		}

		return $result === [] ? null : array_values(array_unique($result));
	}

	public static function toBool(mixed $value): ?bool
	{
		if ($value === null || $value === '')
		{
			return null;
		}

		if (is_bool($value))
		{
			return $value;
		}

		if (is_int($value))
		{
			return match ($value)
			{
				0 => false,
				1 => true,
				default => null,
			};
		}

		if (!is_string($value))
		{
			return null;
		}

		return match (strtolower(trim($value)))
		{
			'1', 'true', 'y', 'yes' => true,
			'0', 'false', 'n', 'no' => false,
			default => null,
		};
	}
}
