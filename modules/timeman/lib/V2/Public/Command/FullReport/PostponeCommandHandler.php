<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Command\FullReport;

use Bitrix\Main\Result;
use Bitrix\Timeman\V2\Internal\Service\FullReportService;

class PostponeCommandHandler
{
	public function __construct(private readonly FullReportService $service)
	{
	}

	public function __invoke(PostponeCommand $command): Result
	{
		return $this->service->postpone($command->userId, $command->seconds);
	}
}
