<?php

namespace Bitrix\Crm\Service\ItemList;

final class CanonicalFilter
{
	public static function hash(array $filter): string
	{
		return md5(self::serialize($filter));
	}

	public static function serialize(array $filter): string
	{
		return serialize(self::canonicalize($filter));
	}

	private static function canonicalize(mixed $value): array
	{
		if (!is_array($value))
		{
			return self::normalizeScalar($value);
		}

		$isList = array_is_list($value);
		if (!$isList)
		{
			ksort($value, SORT_STRING);
		}

		$normalizedItems = [];
		if ($isList)
		{
			foreach ($value as $item)
			{
				$normalizedItems[] = self::canonicalize($item);
			}
		}
		else
		{
			foreach ($value as $key => $item)
			{
				$normalizedItems[] = [
					'keyType' => is_int($key) ? 'int' : 'string',
					'key' => $key,
					'value' => self::canonicalize($item),
				];
			}
		}

		return [
			'type' => $isList ? 'list' : 'map',
			'value' => $normalizedItems,
		];
	}

	private static function normalizeScalar(mixed $value): array
	{
		if (
			$value === null
			|| is_bool($value)
			|| is_int($value)
			|| is_float($value)
			|| is_string($value)
		)
		{
			return [
				'type' => get_debug_type($value),
				'value' => $value,
			];
		}

		return [
			'type' => get_debug_type($value),
			'value' => serialize($value),
		];
	}
}
