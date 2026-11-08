<?php

namespace Bitrix\BIConnector\Superset\Updater\Versions;

use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter\DateTime;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Result;

/**
 * Moves the global default report period to the canonical preset dictionary:
 * a rolling period is replaced with the closest calendar one.
 * Anything outside the map - a canonical value, an empty option, a leftover - is left as is,
 * so a repeated run changes nothing.
 */
final class Version14 extends BaseVersion
{
	private const CANONICAL_PERIOD_MAP = [
		DateTime::PERIOD_LAST_7 => DateTime::PERIOD_CURRENT_WEEK,
		DateTime::PERIOD_LAST_30 => DateTime::PERIOD_CURRENT_MONTH,
		DateTime::PERIOD_LAST_90 => DateTime::PERIOD_CURRENT_MONTH,
		DateTime::PERIOD_LAST_180 => DateTime::PERIOD_CURRENT_YEAR,
		DateTime::PERIOD_LAST_365 => DateTime::PERIOD_CURRENT_YEAR,
	];

	public function run(): Result
	{
		$result = new Result();

		$period = Option::get('biconnector', DateTime::CONFIG_PERIOD_OPTION_NAME, '');
		if (isset(self::CANONICAL_PERIOD_MAP[$period]))
		{
			Option::set('biconnector', DateTime::CONFIG_PERIOD_OPTION_NAME, self::CANONICAL_PERIOD_MAP[$period]);
		}

		return $result;
	}
}
