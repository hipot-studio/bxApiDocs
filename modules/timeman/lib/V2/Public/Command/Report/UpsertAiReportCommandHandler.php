<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Command\Report;

use Bitrix\Main\Result;
use Bitrix\Timeman\Model\Worktime\Report\WorktimeReportTable;
use Bitrix\Timeman\V2\Internal\Service\ReportService;

class UpsertAiReportCommandHandler
{
	public function __construct(private readonly ReportService $service)
	{
	}

	public function __invoke(UpsertAiReportCommand $command): Result
	{
		return $this->service->saveRecordReport(
			$command->recordId,
			$command->userId,
			$command->reportText,
			WorktimeReportTable::REPORT_TYPE_RECORD_AI_REPORT,
		);
	}
}
