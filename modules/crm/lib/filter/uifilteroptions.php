<?php

namespace Bitrix\Crm\Filter;

class UiFilterOptions extends \Bitrix\Main\UI\Filter\Options
{
	public function getFilter($sourceFields = [])
	{
		return self::removeServiceKeys(parent::getFilter($sourceFields));
	}

	/**
	 * Drops internal filter directives from a filter array: raw SQL (__CONDITIONS,
	 * __JOINS), nested logic (__INNER_FILTER*) and the CHECK_PERMISSIONS / LOGIC
	 * switches. These are directives for CCrmEntityListBuilder, never user-saved fields.
	 *
	 * Consumers that strip a tab prefix off filter keys (e.g. crm.activity.list turns
	 * TAB_ACTIVITY___CONDITIONS into __CONDITIONS) must call this again AFTER stripping,
	 * because the prefixed form is not a service key yet when getFilter() runs.
	 */
	public static function removeServiceKeys(array $filter): array
	{
		foreach (array_keys($filter) as $key)
		{
			if (self::isServiceFilterKey((string)$key))
			{
				unset($filter[$key]);
			}
		}

		return $filter;
	}

	/**
	 * A key is a service directive when it starts with "__" (no real field or user field
	 * name does) or equals one of the standalone switches. Matching is intentionally exact
	 * for the switches: a user field may legitimately be named e.g. UF_CRM_LOGIC.
	 */
	public static function isServiceFilterKey(string $key): bool
	{
		if (str_starts_with($key, '__'))
		{
			return true;
		}

		$key = mb_strtoupper($key);

		return $key === 'LOGIC' || $key === 'CHECK_PERMISSIONS';
	}
}
