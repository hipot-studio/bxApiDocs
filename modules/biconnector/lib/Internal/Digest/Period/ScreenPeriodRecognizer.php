<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Period;

use Bitrix\BIConnector\Internal\Digest\DigestPeriodSelection;

/**
 * Turns the time range of a report screen, as Superset states it, into a digest period.
 *
 * The nature of the period is decided on the expression, before it is brought to dates: once computed,
 * a floating period cannot be told from a fixed one.
 */
interface ScreenPeriodRecognizer
{
	/**
	 * @param string $timeRange Superset `time_range`, e.g. `current calendar month` or `2026-01-01 : 2026-02-01`.
	 *
	 * @return DigestPeriodSelection|null An entry of the manual list when the expression is one, a fixed range
	 *     of its dates otherwise, or null when the expression is not recognized: never the nearest entry.
	 */
	public function recognize(string $timeRange): ?DigestPeriodSelection;
}
