<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Command\FullReport;

use Bitrix\Main\Result;
use Bitrix\Timeman\V2\Internal\Service\FullReportService;

class ApproveCommandHandler
{
	public function __construct(private readonly FullReportService $service)
	{
	}

	public function __invoke(ApproveCommand $command): Result
	{
		return $this->service->approve($command->reportId, $command->approverId);
	}
}
