<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Command\Report;

use Bitrix\Main\Result;
use Bitrix\Timeman\V2\Internal\Service\ReportService;
use Bitrix\Timeman\V2\Public\Dto\Report\RecordReportType;

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
			RecordReportType::AI_REPORT,
		);
	}
}
