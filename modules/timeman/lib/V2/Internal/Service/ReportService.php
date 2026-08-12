<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Service;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Timeman\V2\Internal\Entity\Report\RecordReportType;
use Bitrix\Timeman\V2\Internal\Repository\RecordRepository;
use Bitrix\Timeman\V2\Internal\Repository\ReportRepository;

class ReportService
{
	public function __construct(
		private readonly RecordRepository $recordRepository,
		private readonly ReportRepository $reportRepository,
		private readonly FullReportService $fullReportService,
	)
	{
	}

	/**
	 * Adds or updates a worktime record report.
	 *
	 * @param int $recordId Worktime record ID
	 * @param int $userId User ID
	 * @param string $reportText Report text
	 * @param string $reportType Report type constant from RecordReportType
	 * @param bool $scheduleAiReport Whether to schedule daily AI report generation for the user
	 * @return Result
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function saveRecordReport(
		int $recordId,
		int $userId,
		string $reportText,
		string $reportType = RecordReportType::REPORT,
		bool $scheduleAiReport = true,
	): Result
	{
		$result = new Result();

		if (!$this->recordRepository->exists($recordId))
		{
			$result->addError(new Error('Worktime record not found'));

			return $result;
		}

		if (!$this->recordRepository->belongsToUser($recordId, $userId))
		{
			$result->addError(new Error('Worktime record does not belong to the specified user'));

			return $result;
		}

		if ($scheduleAiReport && $reportText && !in_array($reportType, RecordReportType::getPlanValues(), true))
		{
			$this->fullReportService->scheduleGenerationAiReport($userId);
		}

		return $this->reportRepository->upsertRecordReport($recordId, $userId, $reportText, $reportType);
	}
}
