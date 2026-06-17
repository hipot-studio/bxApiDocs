<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Service;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Timeman\Helper\TimeHelper;
use Bitrix\Timeman\V2\Internal\Entity\FullReport\FullReportForm;
use Bitrix\Timeman\V2\Internal\Entity\FullReport\FullReport;
use Bitrix\Timeman\V2\Internal\Entity\FullReport\FullReportApprove;
use Bitrix\Timeman\V2\Internal\Repository\FullReportRepository;
use Bitrix\Timeman\V2\Internal\Repository\RecordRepository;
use Bitrix\Timeman\V2\Internal\Repository\ReportRepository;
use Bitrix\Timeman\V2\Internal\Entity\FullReport\FullReportMark;

class FullReportService
{
	public function __construct(
		private readonly FullReportRepository $fullReportRepository,
		private readonly RecordRepository $recordRepository,
		private readonly ReportRepository $reportRepository,
		private readonly FullReportUserService $fullReportUserService,
	)
	{
	}

	public function add(FullReportForm $reportForm): Result
	{
		$result = new Result();

		$userId = $reportForm->userId;
		if ($userId <= 0)
		{
			$result->addError(new Error('User ID is required'));

			return $result;
		}

		[$dateFrom, $dateTo] = $this->resolveReportPeriod($userId, $reportForm->dateFrom, $reportForm->dateTo);
		if (!$dateFrom || !$dateTo)
		{
			$result->addError(new Error('Failed to build report period'));

			return $result;
		}

		// Avoid overlaps with any existing report: shift period forward if needed.
		[$dateFrom, $dateTo] = $this->shiftPeriodAfterOverlaps($userId, $dateFrom, $dateTo);

		if ($dateFrom->getTimestamp() > $dateTo->getTimestamp())
		{
			$dateTo = clone $dateFrom;
		}

		$dailyText = $reportForm->autoFillDailyReports
			? $this->buildDailyReportsText($userId, $dateFrom->format('Y-m-d'), $dateTo->format('Y-m-d'))
			: '';

		$reportCombined = $this->buildFullReportText(
			baseText: $reportForm->reportText,
			autoDailyText: $dailyText,
		);

		$report = new FullReport(
			id: 0,
			userId: $userId,
			active: true,
			reportDate: time(),
			dateFrom: $dateFrom->getTimestamp(),
			dateTo: $dateTo->getTimestamp(),
			report: $reportCombined,
			plans: $reportForm->plansText,
			tasks: $reportForm->tasks,
			events: $reportForm->events,
			files: $reportForm->files,
			mark: FullReportMark::POSITIVE->value,
			approve: FullReportApprove::YES->value,
			approveDate: time(),
			approver: $this->fullReportUserService->getReportApprover($userId),
			forumTopicId: 0,
			timestamp: null,
		);

		$addResult = $this->fullReportRepository->add($report);
		if (!$addResult->isSuccess())
		{
			$result->addErrors($addResult->getErrors());

			return $result;
		}

		$reportId = (int)($addResult->getData()['id'] ?? 0);
		$result->setData([
			'id' => $reportId,
			'dateFrom' => $dateFrom->getTimestamp(),
			'dateTo' => $dateTo->getTimestamp(),
		]);

		return $result;
	}

	public function update(FullReportForm $reportForm): Result
	{
		$result = new Result();

		$reportId = $reportForm->reportId;
		if ($reportId <= 0)
		{
			return $result->addError(new Error('Report ID is required'));
		}

		$updateFields = $this->buildUpdateFields($reportForm);

		[$from, $to] = $this->applyPeriodUpdateIfNeeded(
			$result,
			$reportId,
			$reportForm->dateFrom,
			$reportForm->dateTo,
			$updateFields,
		);
		if (!$result->isSuccess())
		{
			return $result;
		}

		if (empty($updateFields))
		{
			return $result->addError(new Error('No fields to update'));
		}

		$currentReport = $this->fullReportRepository->getById($reportId);
		if ($currentReport === null)
		{
			return $result->addError(new Error('Report not found'));
		}

		$updatedReport = $currentReport->withChanges($updateFields);
		$updateResult = $this->fullReportRepository->update($updatedReport);
		if (!$updateResult->isSuccess())
		{
			$result->addErrors($updateResult->getErrors());
			return $result;
		}

		$result->setData([
			'id' => (int)$reportId,
			'dateFrom' => $from?->getTimestamp(),
			'dateTo' => $to?->getTimestamp(),
		]);

		return $result;
	}

	public function approve(int $reportId, int $approverId): Result
	{
		return $this->setDecision($reportId, $approverId, FullReportMark::POSITIVE);
	}

	public function reject(int $reportId, int $approverId): Result
	{
		return $this->setDecision($reportId, $approverId, FullReportMark::NEGATIVE);
	}

	private function buildUpdateFields(FullReportForm $reportForm): array
	{
		$fields = [];

		if ($reportForm->reportText !== null)
		{
			$fields['reportText'] = $reportForm->reportText;
		}
		if ($reportForm->plansText !== null)
		{
			$fields['plansText'] = $reportForm->plansText;
		}
		if ($reportForm->tasks !== null)
		{
			$fields['tasks'] = $reportForm->tasks;
		}
		if ($reportForm->events !== null)
		{
			$fields['events'] = $reportForm->events;
		}
		if ($reportForm->files !== null)
		{
			$fields['files'] = $reportForm->files;
		}

		return $fields;
	}

	/**
	 * Applies a period update (DATE_FROM/DATE_TO) to update fields if needed.
	 *
	 * @param Result $result
	 * @param int $reportId
	 * @param ?int $dateFrom
	 * @param ?int $dateTo
	 * @param array<string, mixed> $fields
	 * @return array{0: ?DateTime, 1: ?DateTime} The normalized period which will be saved (or nulls if period isn't updated).
	 */
	private function applyPeriodUpdateIfNeeded(
		Result $result,
		int $reportId,
		?int $dateFrom,
		?int $dateTo,
		array &$fields,
	): array
	{
		$needPeriodUpdate = ($dateFrom !== null || $dateTo !== null);
		if (!$needPeriodUpdate)
		{
			return [null, null];
		}

		[$currentUserId, $currentFrom, $currentTo] = $this->loadReportPeriodContext($result, $reportId);
		if (!$result->isSuccess())
		{
			return [null, null];
		}

		[$from, $to] = $this->buildPeriodFromUtc(
			dateFromUtc: $dateFrom,
			dateToUtc: $dateTo,
			fallbackFrom: $currentFrom,
			fallbackTo: $currentTo,
		);

		if (!$from || !$to)
		{
			$result->addError(new Error('Invalid period values'));

			return [null, null];
		}

		$conflict = $this->findAnyPeriodOverlap($currentUserId, $reportId, $from, $to);
		if ($conflict !== null)
		{
			$result->addError($conflict);

			return [null, null];
		}

		// Persist full normalized range to avoid inverted DATE_FROM/DATE_TO.
		$fields['dateFrom'] = $from->getTimestamp();
		$fields['dateTo'] = $to->getTimestamp();

		return [$from, $to];
	}

	/**
	 * @return array{0: int, 1: ?DateTime, 2: ?DateTime} [userId, currentFrom, currentTo]
	 */
	private function loadReportPeriodContext(Result $result, int $reportId): array
	{
		$context = $this->fullReportRepository->getPeriodContextById($reportId);
		if (!$context)
		{
			$result->addError(new Error('Report not found'));

			return [0, null, null];
		}

		return [(int)$context['userId'], $context['dateFrom'], $context['dateTo']];
	}

	private function findAnyPeriodOverlap(int $userId, int $excludeReportId, DateTime $from, DateTime $to): ?Error
	{
		$conflict = $this->fullReportRepository->findOverlap($userId, $excludeReportId, $from, $to);
		if ($conflict === null)
		{
			return null;
		}

		$conflictId = (int)$conflict['id'];
		$conflictFrom = ($conflict['dateFrom'] instanceof DateTime) ? $conflict['dateFrom']->getTimestamp() : null;
		$conflictTo = ($conflict['dateTo'] instanceof DateTime) ? $conflict['dateTo']->getTimestamp() : null;

		return new Error(
			'Period overlaps with another report',
			'PERIOD_OVERLAP',
			[
				'conflictReportId' => $conflictId,
				'conflictDateFrom' => $conflictFrom,
				'conflictDateTo' => $conflictTo,
			]
		);
	}

	private function resolveReportPeriod(int $userId, ?int $dateFrom, ?int $dateTo): array
	{
		if ($dateFrom && $dateTo)
		{
			return $this->buildPeriodFromUtc(
				dateFromUtc: $dateFrom,
				dateToUtc: $dateTo
			);
		}

		// 2) Legacy settings-based period.
		$periodInfo = (new \CUserReportFull($userId))->GetReportInfo();
		if (is_array($periodInfo))
		{
			[$dateFrom, $dateTo] = $this->buildPeriodDateTimesFromReportInfo($periodInfo);
			if ($dateFrom && $dateTo)
			{
				return [$dateFrom, $dateTo];
			}
		}

		// 3) Fallback: create a single-day report for "today" in user timezone.
		$todayYmd = date('Y-m-d');
		$todayTs = TimeHelper::getInstance()->getTimestampByUserDate($todayYmd, $userId, 'YYYY-MM-DD');
		if (!$todayTs)
		{
			return [null, null];
		}

		$dateFrom = DateTime::createFromTimestamp((int)$todayTs);
		$dateFrom->setTime(0, 0, 0);
		$dateTo = clone $dateFrom;

		return [$dateFrom, $dateTo];
	}

	/**
	 * Builds a normalized report period from UTC timestamps.
	 *
	 * - Applies 00:00:00 time to both dates
	 * - If both bounds are present and inverted, swaps them
	 * - If some bound is missing, falls back to provided DateTime values (if any)
	 *
	 * @param int|null $dateFromUtc
	 * @param int|null $dateToUtc
	 * @param DateTime|null $fallbackFrom
	 * @param DateTime|null $fallbackTo
	 * @return array{0: ?DateTime, 1: ?DateTime}
	 */
	private function buildPeriodFromUtc(
		?int $dateFromUtc,
		?int $dateToUtc,
		?DateTime $fallbackFrom = null,
		?DateTime $fallbackTo = null
	): array
	{
		$from = $this->buildReportDateFromUtc($dateFromUtc, $fallbackFrom);
		$to = $this->buildReportDateFromUtc($dateToUtc, $fallbackTo);

		if ($from && $to && $from->getTimestamp() > $to->getTimestamp())
		{
			[$from, $to] = [$to, $from];
		}

		return [$from, $to];
	}

	private function buildReportDateFromUtc(?int $utcTimestamp, ?DateTime $fallback): ?DateTime
	{
		if ($utcTimestamp === null)
		{
			return $fallback ? clone $fallback : null;
		}

		if ($utcTimestamp <= 0)
		{
			return null;
		}

		// Treat timestamps as true UTC instants.
		// Store DATE_FROM/DATE_TO as midnight for the UTC calendar date.
		$ymd = gmdate('Y-m-d', $utcTimestamp);

		return new DateTime($ymd . ' 00:00:00', 'Y-m-d H:i:s');
	}

	private function shiftPeriodAfterOverlaps(int $userId, DateTime $dateFrom, DateTime $dateTo): array
	{
		$conflictTo = $this->fullReportRepository->findContinuousOverlappingDateTo($userId, $dateFrom, $dateTo);
		if (!$conflictTo)
		{
			return [$dateFrom, $dateTo];
		}

		$dateFrom = DateTime::createFromTimestamp($conflictTo->getTimestamp() + 86400);
		$dateFrom->setTime(0, 0, 0);

		if ($dateFrom->getTimestamp() > $dateTo->getTimestamp())
		{
			$dateTo = clone $dateFrom;
		}

		return [$dateFrom, $dateTo];
	}

	private function buildPeriodDateTimesFromReportInfo(array $reportInfo): array
	{
		$shortFormat = \CSite::getDateFormat('SHORT', SITE_ID);
		$dateFromStr = (string)($reportInfo['DATE_FROM'] ?? '');
		$dateToStr = (string)($reportInfo['DATE_TO'] ?? '');
		if ($dateFromStr === '' || $dateToStr === '')
		{
			return [null, null];
		}

		$dateFromTs = (int)\MakeTimeStamp($dateFromStr, $shortFormat);
		$dateToTs = (int)\MakeTimeStamp($dateToStr, $shortFormat);
		if ($dateFromTs <= 0 || $dateToTs <= 0)
		{
			return [null, null];
		}

		$dateFrom = DateTime::createFromTimestamp($dateFromTs);
		$dateFrom->setTime(0, 0, 0);
		$dateTo = DateTime::createFromTimestamp($dateToTs);
		$dateTo->setTime(0, 0, 0);

		return [$dateFrom, $dateTo];
	}

	private function buildFullReportText(?string $baseText, string $autoDailyText): string
	{
		$base = trim((string)($baseText ?? ''));
		$daily = trim($autoDailyText);
		if ($daily === '')
		{
			return $base;
		}
		if ($base === '')
		{
			return $daily;
		}

		return $base . "\n\n" . $daily;
	}

	private function buildDailyReportsText(int $userId, string $dateFromYmd, string $dateToYmd): string
	{
		$fromTsRaw = TimeHelper::getInstance()->getTimestampByUserDate($dateFromYmd, $userId, 'YYYY-MM-DD');
		$toTsRaw = TimeHelper::getInstance()->getTimestampByUserDate($dateToYmd, $userId, 'YYYY-MM-DD');
		if (!$fromTsRaw || !$toTsRaw)
		{
			return '';
		}

		$fromTs = min((int)$fromTsRaw, (int)$toTsRaw);
		$toTs = max((int)$fromTsRaw, (int)$toTsRaw);
		$toEndTs = $toTs + 86399;

		$entryInfo = $this->recordRepository->getEntryIdsWithStartTimestampsByUserAndRange($userId, $fromTs, $toEndTs);
		$entryIds = $entryInfo['entryIds'];
		$entryStartById = $entryInfo['startById'];
		if (empty($entryIds))
		{
			return '';
		}

		$reportByEntryId = $this->reportRepository->getLatestRecordReportTextByEntryIds($userId, $entryIds);
		if (empty($reportByEntryId))
		{
			return '';
		}

		$lines = [];
		foreach ($entryIds as $entryId)
		{
			if (!array_key_exists($entryId, $reportByEntryId))
			{
				continue;
			}
			$text = trim($reportByEntryId[$entryId]);
			if ($text === '')
			{
				continue;
			}
			$startTs = $entryStartById[$entryId] ?? 0;
			$dateLabel = ($startTs > 0) ? gmdate('Y-m-d', $startTs) : '';
			$prefix = $dateLabel !== '' ? "{$dateLabel} (#{$entryId})" : "#{$entryId}";
			$lines[] = $prefix . ": " . $text;
		}

		if (empty($lines))
		{
			return '';
		}

		return "Daily reports:\n" . implode("\n", $lines);
	}

	private function setDecision(int $reportId, int $approverId, FullReportMark $mark): Result
	{
		$result = new Result();

		if ($reportId <= 0)
		{
			$result->addError(new Error('Report ID is required'));

			return $result;
		}

		if ($approverId <= 0)
		{
			$result->addError(new Error('Approver ID is required'));

			return $result;
		}

		$fields = ['mark' => $mark->value];

		if ($mark === FullReportMark::UNCONFIRMED)
		{
			// Legacy meaning: unconfirm the report.
			$fields['approve'] = FullReportApprove::NO->value;
			$fields['approveDate'] = null;
			$fields['approver'] = 0;
		}
		else
		{
			$fields['approve'] = FullReportApprove::YES->value;
			$fields['approveDate'] = time();
			$fields['approver'] = $approverId;
		}

		$currentReport = $this->fullReportRepository->getById($reportId);
		if ($currentReport === null)
		{
			return $result->addError(new Error('Report not found'));
		}

		$updatedReport = $currentReport->withChanges($fields);
		$updateResult = $this->fullReportRepository->update($updatedReport);
		if (!$updateResult->isSuccess())
		{
			$result->addErrors($updateResult->getErrors());
			return $result;
		}

		$result->setData(['id' => (int)$reportId, 'mark' => $mark->value]);

		return $result;
	}
}
