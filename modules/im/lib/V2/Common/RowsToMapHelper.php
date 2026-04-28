<?php

namespace Bitrix\Im\V2\Common;

class RowsToMapHelper
{
	public static function mapScalar(
		array $rows,
		string $keyForKey,
		string $keyForValue,
		?callable $keyModifier = null,
		?callable $valueModifier = null
	): array
	{
		$result = [];
		foreach ($rows as $row)
		{
			$key = $row[$keyForKey];
			if ($keyModifier !== null)
			{
				$key = $keyModifier($key);
			}
			$value = $row[$keyForValue];
			if ($valueModifier !== null)
			{
				$value = $valueModifier($value);
			}
			$result[$key] = $value;
		}

		return $result;
	}

	public static function mapArray(
		array $rows,
		string $keyForKey,
		?callable $keyModifier = null,
		?callable $valueModifier = null
	): array
	{
		$result = [];
		foreach ($rows as $row)
		{
			$key = $row[$keyForKey];
			if ($keyModifier !== null)
			{
				$key = $keyModifier($key);
			}
			$value = $row;
			unset($row[$keyForKey]);
			if ($valueModifier !== null)
			{
				$value = $valueModifier($value);
			}
			$result[$key] = $value;
		}

		return $result;
	}

	public static function mapIntToArray(array $rows, string $keyForKey, ?callable $valueModifier = null): array
	{
		return self::mapArray($rows, $keyForKey, 'intval', $valueModifier);
	}

	public static function mapIntToInt(array $rows, string $keyForKey, string $keyForValue): array
	{
		return self::mapScalar($rows, $keyForKey, $keyForValue, 'intval', 'intval');
	}

	public static function mapIntKey(array $rows, string $keyForKey, string $keyForValue): array
	{
		return self::mapScalar($rows, $keyForKey, $keyForValue, keyModifier:  'intval');
	}

	public static function mapIntValue(array $rows, string $keyForKey, string $keyForValue): array
	{
		return self::mapScalar($rows, $keyForKey, $keyForValue, valueModifier: 'intval');
	}
}