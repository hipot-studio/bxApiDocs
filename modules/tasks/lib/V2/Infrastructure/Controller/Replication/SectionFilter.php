<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Replication;

use Bitrix\Main\UI\Filter\NumberType;

final class SectionFilter
{
	private const FIELDS = [
		'PRIORITY',
		'RESPONSIBLE_ID',
		'CREATED_BY',
		'GROUP_ID',
		'REPLICATE_TYPE',
		'ID',
		'AUDITOR',
		'ACCOMPLICE',
	];
	private const NUMBER_MODES = [
		NumberType::SINGLE,
		NumberType::RANGE,
		NumberType::MORE,
		NumberType::LESS,
	];
	private const MAX_VALUES = 100;

	public static function normalize(mixed $filter): array
	{
		if (!is_array($filter))
		{
			return [];
		}

		$normalized = [];
		foreach (self::FIELDS as $field)
		{
			if (!array_key_exists($field, $filter))
			{
				continue;
			}

			$value = self::normalizeValue($filter[$field]);
			if ($value !== null)
			{
				$normalized[$field] = $value;
			}
		}

		$numberMode = $filter['ID_numsel'] ?? null;
		if (is_string($numberMode) && in_array($numberMode, self::NUMBER_MODES, true))
		{
			$normalized['ID_numsel'] = $numberMode;
		}

		foreach (['ID_from', 'ID_to'] as $key)
		{
			$value = $filter[$key] ?? null;
			if (is_scalar($value) && $value !== '')
			{
				$normalized[$key] = $value;
			}
		}

		return $normalized;
	}

	private static function normalizeValue(mixed $value): string|int|float|bool|array|null
	{
		if (is_scalar($value))
		{
			return $value;
		}

		if (!is_array($value))
		{
			return null;
		}

		$normalized = [];
		foreach (array_slice($value, 0, self::MAX_VALUES) as $item)
		{
			if (is_scalar($item))
			{
				$normalized[] = $item;
			}
		}

		return $normalized;
	}
}
