<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Services\LoadIndicator;

final class Thresholds
{
	/** Query is considered slow at or above this duration, seconds */
	public const SLOW_SECONDS = 5.0;

	/** Query is considered very slow above this duration, seconds. Forces High load level */
	public const VERY_SLOW_SECONDS = 10.0;

	/** Report period above this many days is treated as "wide" */
	public const PERIOD_WIDE_DAYS = 180;

	/** Selected/total columns ratio that triggers the "many columns" factor */
	public const FIELD_RATIO_LIMIT = 0.8;

	/** Minimum dataset columns required to evaluate the column-ratio factor at all */
	public const MIN_COLUMNS_FOR_RATIO_FACTOR = 5;

	/** Response size threshold for the "large data" factor, bytes */
	public const LARGE_DATA_BYTES = 10 * 1024 * 1024;

	/** Row-count threshold for the "large data" factor */
	public const LARGE_ROWS = 50_000;
}
