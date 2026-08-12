<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Command\Report;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Timeman\Integration\Pull\PushEvent;
use Bitrix\Timeman\Integration\Pull\PushService;
use Bitrix\Timeman\V2\Internal\DI\Container;
use Bitrix\Timeman\V2\Internal\Service\ReportService;
use Bitrix\Timeman\V2\Public\Dto\Report\RecordReportType;

class UpsertPlanCommandHandler
{
	public function __construct(private readonly ReportService $service)
	{
	}

	public function __invoke(UpsertPlanCommand $command): Result
	{
		$result = new Result();

		if (!in_array($command->planType, RecordReportType::getPlanValues(), true))
		{
			$result->addError(new Error('Invalid plan type'));

			return $result;
		}

		if ($command->planText === '')
		{
			$result->addError(new Error('Plan text is empty'));

			return $result;
		}

		$record = Container::getInstance()
			->getRecordRepository()
			->getCurrentRecord($command->userId, false, false)
		;

		if ($record === null || $record->userId !== $command->userId)
		{
			$result->addError(new Error('Worktime day record not found or day is not started'));

			return $result;
		}

		$saveResult = $this->service->saveRecordReport(
			$record->getId(),
			$command->userId,
			$command->planText,
			$command->planType,
			false,
		);

		if (!$saveResult->isSuccess())
		{
			return $saveResult;
		}

		(new PushService())->sendEvent(
			new PushEvent(
				command: 'day_plan_ready',
				recipients: [$command->userId],
				params: [
					'recordId' => $record->getId(),
					'userId' => $command->userId,
				],
				entityId: $record->getId(),
			)
		);

		return $saveResult;
	}
}
