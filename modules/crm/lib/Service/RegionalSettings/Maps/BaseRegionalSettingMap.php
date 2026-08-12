<?php

namespace Bitrix\Crm\Service\RegionalSettings\Maps;

use Bitrix\Crm\Service\RegionalSettings\RegionalSettings;

abstract class BaseRegionalSettingMap
{
	/**
	 * Keys use verbose format: `region=ru|culture=en`, `region=ru`, `culture=en`, `*` for default.
	 * @return array<string, mixed>
	 */
	abstract public function getMap(): array;

	/**
	 * @return array<int, array<int, string>>
	 */
	public function getFallbackOrder(): array
	{
		return [
			[RegionalSettings::DIMENSION_REGION, RegionalSettings::DIMENSION_CULTURE],
			[RegionalSettings::DIMENSION_REGION],
			[RegionalSettings::DIMENSION_CULTURE],
			[RegionalSettings::DEFAULT_KEY],
		];
	}
}
