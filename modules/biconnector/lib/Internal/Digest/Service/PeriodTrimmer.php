<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Service;

use Bitrix\BIConnector\Internal\Integration\AiAssistant\Filter\AppliedFilters;

/**
 * Narrows an explicitly selected digest period down to the year limit the BI
 * tool enforces on caller-supplied filters. The shared calculation lives in
 * {@see AppliedFilters}, which also applies it to the global fallback period.
 *
 * The window is counted back from the end of the selected range, never from
 * today: a historical range would otherwise be shifted away from its data.
 *
 * This helper returns null when no trimming is needed; the readiness service
 * then pins the selected period unchanged.
 */
final class PeriodTrimmer
{
	public const MAX_PERIOD_DAYS = AppliedFilters::MAX_PERIOD_DAYS;

	/**
	 * @param array{from: string, to: string}|null $dashboardPeriod Selected range, ISO-8601 dates.
	 *
	 * @return array{from: string, to: string}|null Trimmed bounds, or null when no period is to be pinned.
	 */
	public function trim(?array $dashboardPeriod): ?array
	{
		return AppliedFilters::trimPeriodToYearLimit($dashboardPeriod);
	}
}
