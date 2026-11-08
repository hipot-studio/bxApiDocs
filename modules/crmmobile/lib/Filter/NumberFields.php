<?php

declare(strict_types=1);

namespace Bitrix\CrmMobile\Filter;

use Bitrix\Crm\Kanban;

/**
 * `ID` as a number for the mobile filter: the drawer offers comparisons over it - equals, more,
 * less, a range - and writes the answer as the three keys of the web form (`_numsel`, `_from`, `_to`).
 *
 * The five classic lists of CRM (lead, deal, contact, company, quote) leave `ID` without a type on
 * purpose: their web builds the condition of a number without asking the type - any `_from` key
 * reaches `Main\Filter\Range`, where "equals" is indistinguishable from a lower bound - and the
 * analytics of CRM reads it only as a range of two bounds (`Crm\Integration\Report\Handler\Base`).
 * Hence both the type and the condition here: over a field they call a text one those providers read
 * a plain value alone, and read it by "contains".
 *
 * The lists that type `ID` themselves are the other case - a dynamic type
 * (`Crm\Filter\ItemDataProvider`) and a store document (`StoreDocumentDataProvider`), both since
 * 2020 - so the condition there is built twice, here and by the provider, out of the same keys. It
 * stays idempotent while the two mappings agree key for key and value for value, as they do today.
 */
final class NumberFields
{
	private const FIELD_IDS = ['ID'];

	/**
	 * How the form of the filter writes an answer about a number: the selector of the comparison and
	 * the two bounds (`Main\UI\Filter\NumberType`).
	 */
	private const FORM_KEY_POSTFIXES = ['_numsel', '_from', '_to'];

	public static function declaresAsNumber(string $fieldId): bool
	{
		return in_array($fieldId, self::FIELD_IDS, true);
	}

	/**
	 * Conditions of the query for every such field the filter holds an answer about.
	 *
	 * The mapping of an answer onto a condition is not written again: it is the one the kanban of the
	 * same section applies to every number of its filter, so a comparison chosen in the drawer
	 * narrows the list and the kanban alike. That mapping reads a plain number left without the keys
	 * of the form - a preset saved by the web form, where the field is a text one - as a number to
	 * equal, and a plain word as no condition at all.
	 *
	 * @param array $filterValues values of the filter, as the storage keeps them
	 * @return array<string, mixed>
	 */
	public static function conditions(array $filterValues): array
	{
		$conditions = [];

		foreach (self::FIELD_IDS as $fieldId)
		{
			$conditions += Kanban::buildNumberFieldCondition($fieldId, $filterValues);
		}

		return $conditions;
	}

	/**
	 * The filter of its owner with the reading it made of the plain value of a field answered here
	 * replaced by the condition read here.
	 *
	 * Only that reading goes, and it is recognised by its value: the key a field of ours lands under
	 * is the plain name of the field, and the debugger of robots writes the items of its session
	 * under that same key - the empty set of no session at all included
	 * (`Crm\Automation\Debugger\DebuggerFilter::prepareFilter`, applied after the fields are read).
	 * Dropped along with the reading, such a condition turns "show nothing" into "show everything
	 * the comparison allows".
	 *
	 * @param array $filter the filter as its owner built it
	 * @param array $filterValues values of the filter, as the storage keeps them
	 * @param callable(string): string $keyOf names the key the owner writes a field under
	 * @return array<string, mixed>
	 */
	public static function applyTo(array $filter, array $filterValues, callable $keyOf): array
	{
		foreach (self::answeredFields($filterValues) as $fieldId)
		{
			if (!array_key_exists($fieldId, $filterValues))
			{
				continue;
			}

			$key = $keyOf($fieldId);
			if (array_key_exists($key, $filter) && $filter[$key] === $filterValues[$fieldId])
			{
				unset($filter[$key]);
			}
		}

		return array_merge($filter, self::conditions($filterValues));
	}

	/**
	 * The conditions of the fields answered here and the values left for the provider of the list,
	 * both read from the values as the storage keeps them.
	 *
	 * Handed out as a pair on purpose. Read in the other order - the condition out of the values
	 * already stripped of the plain one - a field answered by the plain value of a preset loses its
	 * condition altogether, and the list comes back without the restriction the drawer shows.
	 *
	 * @return array{0: array<string, mixed>, 1: array} the conditions, then the values for the provider
	 */
	public static function conditionsAndRest(array $filterValues): array
	{
		return [self::conditions($filterValues), self::withoutPlainValues($filterValues)];
	}

	/**
	 * Fields of ours the filter is answered about here: a condition is built over them, or the
	 * selector of the form stands there without the bound it asks for and the field is deliberately
	 * left without a condition.
	 *
	 * A condition built somewhere else out of the plain value of such a field has to go, whoever
	 * built it: over a number, the reader here has the last word.
	 *
	 * @return string[]
	 */
	public static function answeredFields(array $filterValues): array
	{
		return array_values(array_filter(
			self::FIELD_IDS,
			static fn(string $fieldId): bool => self::isAnswered($filterValues, $fieldId),
		));
	}

	/**
	 * The same values without the plain value of a field answered here.
	 *
	 * Left for the provider of the list, such a value would be read as the text the CRM filter calls
	 * the field, by "contains" - on top of the condition built here, or, when the form asks for a
	 * bound it was not given, in place of the nothing chosen here on purpose.
	 */
	public static function withoutPlainValues(array $filterValues): array
	{
		foreach (self::answeredFields($filterValues) as $fieldId)
		{
			unset($filterValues[$fieldId]);
		}

		return $filterValues;
	}

	private static function isAnswered(array $filterValues, string $fieldId): bool
	{
		return self::hasFormKeys($filterValues, $fieldId)
			|| Kanban::buildNumberFieldCondition($fieldId, $filterValues) !== [];
	}

	private static function hasFormKeys(array $filterValues, string $fieldId): bool
	{
		foreach (self::FORM_KEY_POSTFIXES as $postfix)
		{
			if (isset($filterValues[$fieldId . $postfix]))
			{
				return true;
			}
		}

		return false;
	}
}
