<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Entity\FullReport;

/**
 * Internal input for actions with full report.
 */
final class FullReportForm
{
	public function __construct(
		public readonly ?int $userId = null,
		public readonly ?int $reportId = null,
		public readonly ?string $reportText = null,
		public readonly ?string $plansText = null,
		public readonly ?array $tasks = null,
		public readonly ?array $events = null,
		public readonly ?array $files = null,
		public readonly bool $autoFillDailyReports = true,
		public readonly ?int $dateFrom = null,
		public readonly ?int $dateTo = null,
		public readonly ?string $mark = null,
	)
	{
	}
}
