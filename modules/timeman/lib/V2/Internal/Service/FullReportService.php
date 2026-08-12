<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Service;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Timeman\Helper\TimeHelper;
use Bitrix\Timeman\V2\Internal\Agent\FullReportAiGenerator;
use Bitrix\Timeman\V2\Internal\Entity\FullReport\FullReport;
use Bitrix\Timeman\V2\Internal\Entity\FullReport\FullReportApprove;
use Bitrix\Timeman\V2\Internal\Entity\FullReport\FullReportForm;
use Bitrix\Timeman\V2\Internal\Entity\FullReport\FullReportMark;
use Bitrix\Timeman\V2\Internal\Entity\ScheduledAction\ScheduledActionStatus;
use Bitrix\Timeman\V2\Internal\Entity\ScheduledAction\ScheduledActionType;
use Bitrix\Timeman\V2\Internal\Entity\Report\RecordReportType;
use Bitrix\Timeman\V2\Internal\Repository\FullReportRepository;
use Bitrix\Timeman\V2\Internal\Repository\RecordRepository;
use Bitrix\Timeman\V2\Internal\Repository\ReportRepository;

class FullReportService
{
	public function __construct(
		private readonly FullReportRepository $fullReportRepository,
		private readonly RecordRepository $recordRepository,
		private readonly ReportRepository $reportRepository,
		private readonly FullReportUserService $fullReportUserService,
		private readonly FullReportSendNotifier $fullReportSendNotifier,
		private readonly FullReportFacade $fullReportFacade,
		private readonly ScheduledActionService $scheduledActionService,
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
			active: false,
			reportDate: time(),
			dateFrom: $dateFrom->getTimestamp(),
			dateTo: $dateTo->getTimestamp(),
			report: $reportCombined,
			reportExtended: $reportForm->reportExtended,
			type: $this->normalizeType($reportForm->type),
			plans: $reportForm->plansText,
			tasks: $reportForm->tasks,
			events: $reportForm->events,
			files: $reportForm->files,
			mark: FullReportMark::NEUTRAL->value,
			approve: FullReportApprove::NO->value,
			approveDate: null,
			approver: 0,
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

		$this->clearReportInfoCache($userId);

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

		$this->clearReportInfoCache($currentReport->userId);

		return $result;
	}

	public function send(int $reportId, int $senderId): Result
	{
		$result = new Result();

		if ($reportId <= 0)
		{
			return $result->addError(new Error('Report ID is required'));
		}

		if ($senderId <= 0)
		{
			return $result->addError(new Error('Sender ID is required'));
		}

		$currentReport = $this->fullReportRepository->getById($reportId);
		if ($currentReport === null)
		{
			return $result->addError(new Error('Report not found'));
		}

		if ($currentReport->active === true)
		{
			$result->setData(['id' => $reportId]);
			$this->clearReportInfoCache($currentReport->userId);

			return $result;
		}

		$approverIds = $this->fullReportUserService->getManagerIds($currentReport->userId);
		$externalApproverIds = array_values(array_filter(
			$approverIds,
			static fn (int $approverId): bool => $approverId > 0 && $approverId !== $currentReport->userId,
		));
		$fields = [
			'active' => true,
			'mark' => FullReportMark::NEUTRAL->value,
		];

		if (!empty($externalApproverIds))
		{
			$fields['approve'] = FullReportApprove::NO->value;
			$fields['approveDate'] = null;
			$fields['approver'] = 0;
		}
		else
		{
			$fields['approve'] = FullReportApprove::YES->value;
			$fields['approveDate'] = time();
			$fields['approver'] = $currentReport->userId;
		}

		$updatedReport = $currentReport->withChanges($fields);
		$updateResult = $this->fullReportRepository->update($updatedReport);
		if (!$updateResult->isSuccess())
		{
			$result->addErrors($updateResult->getErrors());

			return $result;
		}

		$this->clearDelay($currentReport->userId);
		$this->clearReportInfoCache($currentReport->userId);
		if (!empty($externalApproverIds))
		{
			$this->fullReportSendNotifier->notifyManagerAboutSentReport(
				report: $updatedReport,
				senderId: $senderId,
				managerIds: $externalApproverIds,
			);
		}

		$result->setData(['id' => $reportId]);

		return $result;
	}

	public function approve(int $reportId, int $approverId): Result
	{
		return $this->setDecision($reportId, $approverId, FullReportMark::POSITIVE);
	}

	public function reject(int $reportId, int $approverId): Result
	{
		return $this->setDecision($reportId, $approverId, FullReportMark::NEUTRAL);
	}

	public function scheduleGenerationAiReport(int $userId): void
	{
		$reportToSend = $this->fullReportFacade->getReportToSend($userId, true);
		$reportDate = (int)($reportToSend['reportDate'] ?? 0);
		if ($reportDate)
		{
			$this->addFullReportAiGeneratorAgent($userId, $reportDate);
		}
	}

	private function addFullReportAiGeneratorAgent(int $userId, int $reportDateTimestamp): void
	{
		$executeTime = $this->buildFullReportAiGeneratorExecuteTime($reportDateTimestamp);

		$registrationResult = $this->scheduledActionService->register(
			type: ScheduledActionType::FullReportAiGenerate->value,
			userId: $userId,
			executeTime: $executeTime,
			status: ScheduledActionStatus::Pending,
		);
		if (
			!$registrationResult->isCreated()
			|| !$registrationResult->actionId
		)
		{
			return;
		}

		$agentName = FullReportAiGenerator::class . '::execute(' . $userId . ', ' . $executeTime . ');';

		\CAgent::addAgent(
			$agentName,
			'timeman',
			'N',
			60,
			'',
			'Y',
			$this->buildAgentNextExec($executeTime),
			100,
			false,
			false,
		);
	}

	private function buildFullReportAiGeneratorExecuteTime(int $reportDateTimestamp): int
	{
		$utcDateTime = (new \DateTimeImmutable('@' . $reportDateTimestamp))
			->setTimezone(new \DateTimeZone('UTC'));

		$secondsFromDayStart = ((int)$utcDateTime->format('G') * 3600)
			+ ((int)$utcDateTime->format('i') * 60)
			+ (int)$utcDateTime->format('s');

		$offsetInSeconds = (int)floor($secondsFromDayStart / 4);

		return $reportDateTimestamp - $offsetInSeconds;
	}

	private function buildAgentNextExec(int $executeTime): string
	{
		return \ConvertTimeStamp($executeTime + \CTimeZone::GetOffset(), 'FULL');
	}

	private function buildUpdateFields(FullReportForm $reportForm): array
	{
		$fields = [];

		if ($reportForm->reportText !== null)
		{
			$fields['reportText'] = $reportForm->reportText;
		}
		if ($reportForm->reportExtended !== null)
		{
			$fields['reportExtended'] = $reportForm->reportExtended;
		}
		if ($reportForm->type !== null)
		{
			$fields['type'] = $this->normalizeType($reportForm->type);
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

	private function normalizeType(?string $type): string
	{
		return RecordReportType::normalize($type);
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

		[$from, $to] = $this->buildPeriodFromTimestamps(
			dateFromTimestamp: $dateFrom,
			dateToTimestamp: $dateTo,
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
			return $this->buildPeriodFromTimestamps(
				dateFromTimestamp: $dateFrom,
				dateToTimestamp: $dateTo
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
	 * Builds a normalized report period from timestamps.
	 *
	 * - Applies 00:00:00 time to both dates
	 * - If both bounds are present and inverted, swaps them
	 * - If some bound is missing, falls back to provided DateTime values (if any)
	 *
	 * @param int|null $dateFromTimestamp
	 * @param int|null $dateToTimestamp
	 * @param DateTime|null $fallbackFrom
	 * @param DateTime|null $fallbackTo
	 * @return array{0: ?DateTime, 1: ?DateTime}
	 */
	private function buildPeriodFromTimestamps(
		?int $dateFromTimestamp,
		?int $dateToTimestamp,
		?DateTime $fallbackFrom = null,
		?DateTime $fallbackTo = null
	): array
	{
		$from = $this->buildReportDateFromTimestamp($dateFromTimestamp, $fallbackFrom);
		$to = $this->buildReportDateFromTimestamp($dateToTimestamp, $fallbackTo);

		if ($from && $to && $from->getTimestamp() > $to->getTimestamp())
		{
			[$from, $to] = [$to, $from];
		}

		return [$from, $to];
	}

	private function buildReportDateFromTimestamp(?int $timestamp, ?DateTime $fallback): ?DateTime
	{
		if ($timestamp === null)
		{
			return $fallback ? clone $fallback : null;
		}

		if ($timestamp <= 0)
		{
			return null;
		}

		$date = DateTime::createFromTimestamp($timestamp);
		$date->setTime(0, 0, 0);

		return $date;
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

		$reportTexts = $this->reportRepository->getReportTexts($userId, $entryIds);
		if (empty($reportTexts))
		{
			return '';
		}

		$lines = [];
		foreach ($entryIds as $entryId)
		{
			if (!array_key_exists($entryId, $reportTexts))
			{
				continue;
			}
			$text = trim($reportTexts[$entryId]);
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

		return implode("\n", $lines);
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

		if (
			$mark === FullReportMark::UNCONFIRMED
			|| $mark === FullReportMark::NEUTRAL
		)
		{
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
		$this->clearReportInfoCache($currentReport->userId);

		return $result;
	}

	private function clearDelay(int $userId): void
	{
		$user = new \CUser();
		$user->Update($userId, ['UF_DELAY_TIME' => '']);
		\CReportSettings::clearCache($userId);
	}

	private function clearReportInfoCache(int $userId): void
	{
		\CUserReportFull::clearReportCache($userId);
	}

	public function postpone(int $userId, int $seconds = 3600): Result
	{
		$result = new Result();

		if ($userId <= 0)
		{
			$result->addError(new Error('User ID is required'));

			return $result;
		}

		if ($seconds <= 0)
		{
			$result->addError(new Error('Seconds must be positive'));

			return $result;
		}

		$user = new \CUser();
		$user->Update($userId, ['UF_DELAY_TIME' => time() + $seconds]);
		\CReportSettings::clearCache($userId);
		$this->clearReportInfoCache($userId);

		return $result;
	}
}
