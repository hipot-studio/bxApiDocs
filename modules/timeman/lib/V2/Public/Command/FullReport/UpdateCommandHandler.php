<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Command\FullReport;

use Bitrix\Main\Result;
use Bitrix\Timeman\V2\Internal\Entity\FullReport\FullReportForm;
use Bitrix\Timeman\V2\Internal\Service\FullReportService;

class UpdateCommandHandler
{
	public function __construct(private readonly FullReportService $service)
	{
	}

	public function __invoke(UpdateCommand $command): Result
	{
		$reportForm = new FullReportForm(
			reportId: $command->reportId,
			reportText: $command->reportText,
			reportExtended: $command->reportExtended,
			type: $command->type,
			plansText: $command->plansText,
			tasks: $command->tasks,
			events: $command->events,
			files: $command->files,
			dateFrom: $command->dateFrom,
			dateTo: $command->dateTo,
			mark: $command->mark,
		);

		return $this->service->update($reportForm);
	}
}
