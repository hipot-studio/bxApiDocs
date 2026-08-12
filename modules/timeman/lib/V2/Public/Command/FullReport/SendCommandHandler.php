<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Command\FullReport;

use Bitrix\Main\Result;
use Bitrix\Timeman\V2\Internal\Service\FullReportService;

class SendCommandHandler
{
	public function __construct(private readonly FullReportService $service)
	{
	}

	public function __invoke(SendCommand $command): Result
	{
		return $this->service->send($command->reportId, $command->senderId);
	}
}
