<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\History\Service;

use Bitrix\Tasks\Internals\Task\Report;

class TaskReportService
{
	public function fillReport(string $report): ?string
	{
		return Report::getMessage($report);
	}
}
