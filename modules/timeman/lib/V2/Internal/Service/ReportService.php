<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Service;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Timeman\Model\Worktime\Report\WorktimeReportTable;
use Bitrix\Timeman\V2\Internal\Repository\RecordRepository;
use Bitrix\Timeman\V2\Internal\Repository\ReportRepository;

class ReportService
{
	public function __construct(
		private readonly RecordRepository $recordRepository,
		private readonly ReportRepository $reportRepository,
	)
	{
	}

	/**
	 * Adds or updates a worktime record report.
	 *
	 * @param int $recordId Worktime record ID
	 * @param int $userId User ID
	 * @param string $reportText Report text
	 * @param string $reportType Report type constant from WorktimeReportTable
	 * @return Result
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function saveRecordReport(
		int $recordId,
		int $userId,
		string $reportText,
		string $reportType = WorktimeReportTable::REPORT_TYPE_RECORD_REPORT,
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

		return $this->reportRepository->upsertRecordReport($recordId, $userId, $reportText, $reportType);
	}
}
