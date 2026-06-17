<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Repository\Mapper;

use Bitrix\Timeman\Model\Worktime\Report\EO_WorktimeReport;
use Bitrix\Timeman\V2\Internal\Entity\Report\Report;
use Bitrix\Timeman\V2\Internal\Entity\Report\ReportCollection;

class ReportMapper
{
	public function mapToCollection(array $reports): ReportCollection
	{
		$entities = [];
		foreach ($reports as $report)
		{
			$entities[] = $this->mapToEntity($report);
		}

		return new ReportCollection(...$entities);
	}

	public function mapToEntity(array $report): Report
	{
		$timestamp = Report::mapDateTime($report, 'TIMESTAMP_X');

		return new Report(
			id: Report::mapInteger($report, 'ID', 0) ?? 0,
			recordId: Report::mapInteger($report, 'ENTRY_ID', 0) ?? 0,
			userId: Report::mapInteger($report, 'USER_ID', 0) ?? 0,
			type: Report::mapString($report, 'REPORT_TYPE', '') ?? '',
			report: Report::mapString($report, 'REPORT', '') ?? '',
			timestamp: $timestamp?->getTimestamp() ?? 0,
		);
	}

	public function mapFromOrm(EO_WorktimeReport $ormReport): Report
	{
		return new Report(
			id: (int)$ormReport->getId(),
			recordId: (int)$ormReport->getEntryId(),
			userId: (int)$ormReport->getUserId(),
			type: (string)$ormReport->getReportType(),
			report: (string)$ormReport->getReport(),
			timestamp: (int)($ormReport->getTimestampX()?->getTimestamp() ?? 0),
		);
	}
}
