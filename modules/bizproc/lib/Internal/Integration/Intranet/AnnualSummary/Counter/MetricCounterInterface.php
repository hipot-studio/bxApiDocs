<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\Intranet\AnnualSummary\Counter;

use Bitrix\Main\Type\Date;

/**
 * One metric of the annual summary: its name and one count over a batch of employees.
 * Knows nothing about the agent of intranet and its exchange protocol.
 */
interface MetricCounterInterface
{
	/**
	 * Name of the metric, written to the NAME column of the summary row.
	 */
	public function getMetric(): string;

	/**
	 * The period is half-open: from is included, to is not.
	 *
	 * @param int[] $userIds Non-empty list of employee ids.
	 * @return array<int, int> Counts by employee id; employees without activity may be omitted.
	 */
	public function countByUsers(array $userIds, Date $from, Date $to): array;
}
