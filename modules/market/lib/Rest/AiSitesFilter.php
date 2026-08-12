<?php

namespace Bitrix\Market\Rest;

use Bitrix\Landing\Copilot\Manager;
use Bitrix\Main\Loader;

/**
 * AI sites entrypoints in the marketplace catalog.
 *
 * The marketplace service builds a separate response for box portals (recognized by license_key)
 * and ignores the landing_copilot_available flag there, so items marked with IS_AI_SITES arrive
 * even when the release option is off. Until the service is fixed, such items are dropped here.
 *
 * The service also assigns the single/row layout by position, so dropping an item shifts the grid.
 * Modified collection lists get their layout re-stamped to keep the same alternation the service
 * would have produced without the extra item.
 */
class AiSitesFilter
{
	private const ITEM_MARKER = 'IS_AI_SITES';
	private const LAYOUT_PERIOD = 4;
	private const SINGLE_VIEW_POSITIONS = [0, 3];

	public static function isAvailable(): bool
	{
		return Loader::includeModule('landing')
			&& Manager::isAiSitesEnabled()
			&& Loader::includeModule('ai')
		;
	}

	public static function filterResponse(array $response): array
	{
		if (self::isAvailable())
		{
			return $response;
		}

		return self::removeMarkedItems($response);
	}

	private static function removeMarkedItems(array $data): array
	{
		$isList = ($data === [] || array_keys($data) === range(0, count($data) - 1));
		$result = [];
		$removed = false;

		foreach ($data as $key => $value)
		{
			if (is_array($value))
			{
				if (($value[self::ITEM_MARKER] ?? null) === 'Y')
				{
					$removed = true;

					continue;
				}

				$value = self::removeMarkedItems($value);
			}

			$result[$key] = $value;
		}

		if (!$isList)
		{
			return $result;
		}

		$result = array_values($result);

		return $removed ? self::restoreLayout($result) : $result;
	}

	private static function restoreLayout(array $items): array
	{
		foreach ($items as $index => $item)
		{
			if (!is_array($item) || (!isset($item['SINGLE_VIEW']) && !isset($item['ONE_ROW'])))
			{
				continue;
			}

			$isSingleView = in_array($index % self::LAYOUT_PERIOD, self::SINGLE_VIEW_POSITIONS, true);

			$items[$index]['SINGLE_VIEW'] = $isSingleView ? 'Y' : null;
			$items[$index]['ONE_ROW'] = $isSingleView ? null : 'Y';
		}

		return $items;
	}
}
