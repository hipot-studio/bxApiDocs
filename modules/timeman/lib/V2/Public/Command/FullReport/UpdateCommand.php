<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Command\FullReport;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Timeman\V2\Internal\DI\Container;

class UpdateCommand extends AbstractCommand
{
	/**
	 * @param ?string $reportText Optional. If empty and autoFillDailyReports=true, report will be built from daily reports.
	 * @param ?int $dateFrom If provided together with dateTo, overrides period from CUserReportFull::GetReportInfo().
	 * @param ?int $dateTo If provided together with dateFrom, overrides period from CUserReportFull::GetReportInfo().
	 */
	public function __construct(
		#[PositiveNumber]
		public readonly int $reportId,
		public readonly ?string $reportText = null,
		public readonly ?string $plansText = null,
		public readonly ?array $tasks = null,
		public readonly ?array $events = null,
		public readonly ?array $files = null,
		public readonly ?int $dateFrom = null,
		public readonly ?int $dateTo = null,
		public readonly ?string $mark = null,
	)
	{
	}

	protected function execute(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(UpdateCommandHandler::class);

		try
		{
			return $handler($this);
		}
		catch (\Exception $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}
	}
}
