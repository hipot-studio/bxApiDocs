<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Command\FullReport;

use Bitrix\Main\Result;
use Bitrix\Timeman\V2\Internal\Entity\FullReport\FullReportForm;
use Bitrix\Timeman\V2\Internal\Service\FullReportService;

class AddCommandHandler
{
	public function __construct(private readonly FullReportService $service)
	{
	}

	public function __invoke(AddCommand $command): Result
	{
		$reportForm = new FullReportForm(
			userId: $command->userId,
			reportText: $command->reportText,
			plansText: $command->plansText,
			tasks: $command->tasks,
			events: $command->events,
			files: $command->files,
			autoFillDailyReports: $command->autoFillDailyReports,
			dateFrom: $command->dateFrom,
			dateTo: $command->dateTo,
		);

		return $this->service->add($reportForm);
	}
}
