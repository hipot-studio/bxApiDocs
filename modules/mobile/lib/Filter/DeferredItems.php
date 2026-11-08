<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Filter;

/**
 * Leaves the reference book of a field on the server until the form actually needs it.
 *
 * The dictionary of a list describes every field the web filter offers, and a CRM list offers
 * hundreds of values across its selects - stages of every funnel, sources, currencies, forms of the
 * portal. The form needs the values of a field in two cases only: to word the value the filter
 * already holds, and to draw the picker of that field once it is opened. Everything else is paid for
 * on every opening of the drawer and read by nobody.
 *
 * So the values travel with the fields some filter of the list fills - the current one and every
 * saved preset, because a preset applied on mobile has to word its own chips - and every other list
 * field says `itemsDeferred`. The client asks for those by `getFilterFieldItems` when the picker of
 * the field is opened, and keeps the answer for the rest of the session.
 *
 * Applied before ItemSetIndex: what is left after this is what may still be shared, and the fields
 * carry no pointers yet.
 */
final class DeferredItems
{
	/**
	 * Says the values of the field are still on the server. Absent - and not `false` - on every
	 * field that carries its own: a flag no client reads is one more key per field on the wire.
	 */
	public const FLAG = 'itemsDeferred';

	/**
	 * How many values the field has, said while the values themselves stay here. The picker opens a
	 * sheet as tall as what it is about to draw, and that is a row per value: a picker that learns
	 * the number together with the values has nothing to count at the moment it opens, so the sheet
	 * takes the whole screen and stays that tall once a handful of rows arrive into it.
	 */
	public const COUNT = 'itemsCount';

	/**
	 * @param array<int, array> $fields normalized descriptors (CONTRACT-DTO-01)
	 * @param string[] $usedFieldIds keys the filters of the list fill, see usedFieldIds()
	 * @return array<int, array>
	 */
	public static function withhold(array $fields, array $usedFieldIds): array
	{
		$used = array_flip($usedFieldIds);

		$result = [];
		foreach ($fields as $field)
		{
			$result[] = self::isDeferrable($field, $used) ? self::defer($field) : $field;
		}

		return $result;
	}

	/**
	 * Keys the filters of the list fill, in the order they are met and once each.
	 *
	 * The keys are returned as the storage shared with the web writes them, and not resolved to
	 * fields: a condition of the web is a key of its own - `_from`, `_to`, `_isEmpty` - and a
	 * condition on the presence of a value is worded by the dictionary of the controls, not by the
	 * values of the field. So a field asks for its values only when a filter names it plainly.
	 *
	 * @param array<int, array|object> $filters values of the current filter and of the saved presets
	 * @return string[]
	 */
	public static function usedFieldIds(array $filters): array
	{
		$used = [];
		foreach ($filters as $filter)
		{
			foreach ((array)$filter as $key => $value)
			{
				if (self::isFilled($value))
				{
					$used[(string)$key] = true;
				}
			}
		}

		return array_keys($used);
	}

	/**
	 * Values of one field of the list.
	 *
	 * @param array<int, array> $fields normalized descriptors
	 * @return array<int, array>|null null when the list has no such field or it holds no values
	 */
	public static function itemsOf(array $fields, string $fieldId): ?array
	{
		foreach ($fields as $field)
		{
			if ((string)($field['id'] ?? '') === $fieldId)
			{
				return is_array($field['items'] ?? null) ? $field['items'] : null;
			}
		}

		return null;
	}

	/**
	 * A field with values no filter of the list asks for. An empty reference book stays where it is:
	 * asking for it would answer the same emptiness, and the client would pay a request for nothing.
	 *
	 * @param array<string, true> $used
	 */
	private static function isDeferrable(array $field, array $used): bool
	{
		return ($field['type'] ?? '') === FieldType::ListItems->value
			&& is_array($field['items'] ?? null)
			&& $field['items'] !== []
			&& !isset($used[(string)($field['id'] ?? '')]);
	}

	private static function defer(array $field): array
	{
		$field[self::COUNT] = count($field['items']);
		unset($field['items']);
		$field[self::FLAG] = true;

		return $field;
	}

	/**
	 * Whether the value keeps the field in the filter - the same test the serializer of the client
	 * applies: a zero is a condition, an empty string and an empty selection are not.
	 */
	private static function isFilled(mixed $value): bool
	{
		if (is_array($value))
		{
			return $value !== [];
		}

		return $value !== null && $value !== '' && $value !== false;
	}
}
