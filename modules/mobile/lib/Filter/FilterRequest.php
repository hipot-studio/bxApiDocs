<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Filter;

use Bitrix\Main\UI\Filter\Options;

/**
 * Decisions a list request makes about the filter, as pure functions of the request: which preset the
 * conditions belong to, what the request carries next to the preset and what is written into the
 * storage shared with the web.
 *
 * The protocol of the unified filter and not of one module: every list speaks it, whoever owns the
 * list - the caller keeps the reading and the writing, and everything that can be decided out of the
 * request alone lives here, so that it can be tested without a portal behind it. How a list is
 * identified stays with its module (see ListContext), so the conditions of the address of a list -
 * a funnel, a project - are merged by the module over what is built here.
 */
final class FilterRequest
{
	/**
	 * The preset the conditions of the form live in - the one the web filter itself writes unsaved
	 * conditions to.
	 */
	public const TMP_PRESET_ID = Options::TMP_FILTER;

	/** The years switcher of a date field, which `Options::getRowsFromFields()` does not know */
	private const ALLOW_YEAR_SUFFIX = '_allow_year';

	/**
	 * `default_filter` names the form of the filter and not a saved preset, and `Options::getFilter()`
	 * answers an empty filter for it by definition. The conditions of the form are kept in the
	 * temporary preset, so both sides of a request - the write and the read - have to name the
	 * resolved id, or the conditions are written where nobody reads them.
	 */
	public static function resolvePresetId(string $presetId): string
	{
		return Options::isDefaultFilter($presetId) ? self::TMP_PRESET_ID : $presetId;
	}

	/**
	 * The unified filter names no preset while it has not read the state of the list: the client sends
	 * an empty id on the first request of a screen, before its dictionary arrives. The list then shows
	 * what the storage shared with the web holds, the way the web itself does - instead of showing
	 * everything and writing that emptiness back over the filter of the user.
	 *
	 * The legacy filter always names a preset (the tab falls back to its default one), so an empty id
	 * belongs to the unified client alone. The flag is still weighed: a portal with the unified filter
	 * off keeps the behaviour it has now, whoever sends the request.
	 */
	public static function usesStoredFilter(string $presetId, bool $unifiedFiltersEnabled): bool
	{
		return $presetId === '' && $unifiedFiltersEnabled;
	}

	/**
	 * Whether the values of the form the request carries decide the list at all. Only a unified client
	 * sends them, and it goes on sending them for a while after the flag of the portal is switched
	 * off: the value of the flag is mirrored on the device, so a screen already built keeps the mode
	 * it started in. A portal with the unified filter off answers every request the way it did before
	 * the filter existed - the values are left alone and the list is read under the preset the request
	 * names, the way the legacy client asks for it.
	 */
	public static function usesFilterValues(bool $requestCarriesValues, bool $unifiedFiltersEnabled): bool
	{
		return $requestCarriesValues && $unifiedFiltersEnabled;
	}

	/**
	 * The search string of the request as a condition of the list. It is merged over the values of the
	 * preset, so it wins over it.
	 *
	 * Only an absent string is no condition: `'0'` is a search like any other, and emptiness has to be
	 * decided the same way before the form of the filter is read and after it - a request is answered
	 * by what it carries and not by the moment it was sent.
	 */
	public static function buildSearchFilter(array $params): array
	{
		$search = $params['search'] ?? null;
		if (!is_scalar($search) || (string)$search === '')
		{
			return [];
		}

		return [SavedFilterStorage::SEARCH_FIELD => (string)$search];
	}

	/**
	 * Values of the form as the storage keeps them. The client sends them under `tmpFields`, keyed the
	 * way the web keys the values of a preset (`CLOSEDATE_datesel` and the like).
	 */
	public static function extractTmpFields(?array $currentFilter): array
	{
		$fields = ($currentFilter['tmpFields'] ?? null);

		return is_array($fields) ? $fields : [];
	}

	/**
	 * Settings of the temporary preset: the values of the form and the rows they fill.
	 */
	public static function buildTmpPresetSettings(?array $currentFilter): array
	{
		$fields = self::extractTmpFields($currentFilter);

		return [
			'fields' => $fields,
			'preset_id' => self::TMP_PRESET_ID,
			'rows' => self::resolveRows($fields),
		];
	}

	/**
	 * Settings of a saved preset: it is resent whole, so that a request naming it does not thin out
	 * the preset the web shares.
	 */
	public static function buildSavedPresetSettings(string $presetId, array $preset): array
	{
		$fields = ($preset['fields'] ?? []);
		$fields = is_array($fields) ? $fields : [];

		return [
			'fields' => $fields,
			'preset_id' => $presetId,
			// `rows` is the list of fields the form shows, so it takes field ids and not the keys of
			// their values: a `CLOSEDATE_datesel` written here names no field, and the storage is
			// shared with the web, whose form then loses the row
			'rows' => (self::extractRows($preset) ?? self::resolveRows($fields)),
			'name' => ($preset['name'] ?? ''),
		];
	}

	/**
	 * Rows of a preset as the storage names them, null when it names none.
	 *
	 * A preset that carries its rows is resent with them and not with a set rebuilt out of its values:
	 * a row the user added and left empty holds no value, so a rebuilt set drops it and the form of the
	 * web list comes back without the row the user put there. Rebuilding stays the answer for a preset
	 * that reaches the request without its rows at all.
	 *
	 * @return string[]|null
	 */
	private static function extractRows(array $preset): ?array
	{
		$rows = ($preset['filter_rows'] ?? null);

		if (!is_string($rows) || trim($rows) === '')
		{
			return null;
		}

		$ids = array_map('trim', explode(',', $rows));

		return array_values(array_filter($ids, static fn(string $id): bool => $id !== ''));
	}

	/**
	 * Fields of a preset as the rows of the form name them: `Options::getRowsFromFields()` is the
	 * reduction the web itself applies to the keys of the values.
	 *
	 * Two keys of the unified client come out of it naming no field, and a row naming no field is a row
	 * the web form cannot draw: the search string is not a field of the form at all, and the years
	 * switcher of a date is reduced to `_allow` - the list of suffixes there names `_year` and not
	 * `_allow_year`. The switcher belongs to the date beside it, so it is named as that date; the
	 * search string is left out.
	 *
	 * @return string[]
	 */
	public static function resolveRows(array $fields): array
	{
		$keys = [];

		foreach (array_keys($fields) as $key)
		{
			$key = (string)$key;

			if ($key === SavedFilterStorage::SEARCH_FIELD)
			{
				continue;
			}

			$key = (
				str_ends_with($key, self::ALLOW_YEAR_SUFFIX)
					? substr($key, 0, -strlen(self::ALLOW_YEAR_SUFFIX))
					: $key
			);

			// the reduction below reads the keys, so the renamed one goes in as a key as well
			$keys[$key] = true;
		}

		return array_values(Options::getRowsFromFields($keys));
	}

	/**
	 * Values of a preset the rows of the form still hold. `filter_rows` loses the columns a
	 * restriction closed while `fields` keeps their values, and a value with no row of its own would
	 * go on filtering the list invisibly.
	 *
	 * @param string $filterRows comma separated row ids, as the storage keeps them
	 */
	public static function filterAllowedFields(array $fields, string $filterRows): array
	{
		$rows = explode(',', $filterRows);
		$allowed = [];

		foreach ($fields as $fieldName => $fieldValue)
		{
			if (self::isFieldInRows((string)$fieldName, $rows))
			{
				$allowed[$fieldName] = $fieldValue;
			}
		}

		return $allowed;
	}

	private static function isFieldInRows(string $fieldName, array $rows): bool
	{
		if (in_array($fieldName, $rows, true))
		{
			return true;
		}

		$rowsFromFields = Options::getRowsFromFields([$fieldName => '']);

		return in_array(reset($rowsFromFields), $rows, true);
	}
}
