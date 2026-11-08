<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Rooms;

final class RecurrenceRuleSource
{
	public const SOURCE_FIELD = '_CALENDAR_OCCUPANCY_RRULE_SOURCE';
	public const VALUE_FIELD = '_CALENDAR_OCCUPANCY_RRULE_SOURCE_VALUE';
	public const NORMALIZED_ARRAY = 'normalized_array';
	private const REQUEST_STRING = 'request_string';
	private const PERSISTED_STRING = 'persisted_string';

	public static function resetDerivedTimestamps(array &$fields): void
	{
		if (($fields[self::SOURCE_FIELD] ?? null) === self::NORMALIZED_ARRAY)
		{
			unset($fields['DATE_FROM_TS_UTC'], $fields['DATE_TO_TS_UTC']);
		}
	}

	public static function restoreNormalized(array &$fields): void
	{
		$rule = $fields[self::VALUE_FIELD] ?? null;
		if (
			($fields[self::SOURCE_FIELD] ?? null) === self::NORMALIZED_ARRAY
			&& is_string($rule)
			&& is_string($fields['RRULE'] ?? null)
			&& $fields['RRULE'] === $rule
		)
		{
			$fields['RRULE'] = \CCalendarEvent::ParseRRULE($rule);
			unset($fields['RRULE']['UNTIL_TS']);
		}
	}

	public static function markNormalized(array &$fields, string $packedRule): void
	{
		$source = $fields[self::SOURCE_FIELD] ?? null;
		if ($source === null || $source === self::NORMALIZED_ARRAY)
		{
			$fields[self::SOURCE_FIELD] = self::NORMALIZED_ARRAY;
			$fields[self::VALUE_FIELD] = $packedRule;
		}
	}

	public static function clearInvalid(array &$fields): void
	{
		if (($fields[self::SOURCE_FIELD] ?? null) === self::NORMALIZED_ARRAY)
		{
			unset($fields[self::SOURCE_FIELD], $fields[self::VALUE_FIELD]);
		}
	}

	/** @return array|string|null|false False denotes an untrusted string. */
	public static function resolveForOccupancy(array $event): array|string|null|false
	{
		$source = $event[self::SOURCE_FIELD] ?? null;
		if ($source === self::REQUEST_STRING)
		{
			return false;
		}
		$rule = $event['RRULE'] ?? null;
		if (is_string($rule))
		{
			if ($source === self::NORMALIZED_ARRAY)
			{
				return $rule === ($event[self::VALUE_FIELD] ?? null) ? $rule : false;
			}
			if ($source !== self::PERSISTED_STRING)
			{
				return false;
			}

			return $event[self::VALUE_FIELD] ?? $rule;
		}

		return $source === self::PERSISTED_STRING ? ($event[self::VALUE_FIELD] ?? null) : $rule;
	}
}
