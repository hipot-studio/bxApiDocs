<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Service;

use Bitrix\Timeman\V2\Internal\Entity\Report\RecordReportType;
use Bitrix\Timeman\V2\Internal\Repository\FullReportRepository;

final class FullReportFacade
{
	public function __construct(
		private readonly FullReportRepository $fullReportRepository,
	)
	{
	}

	public function getReportToSend(int $userId, bool $force = false, bool $withDraftFallback = false): array
	{
		$legacyPayload = $this->loadLegacyPayload($userId, $force);
		$reportInfo = $this->extractArray($legacyPayload, 'REPORT_INFO');
		$reportData = $this->extractArray($legacyPayload, 'REPORT_DATA');

		if ($withDraftFallback && empty($reportData))
		{
			$draftPayload = $this->loadDraftPayload($userId);
			if ($draftPayload !== null)
			{
				$reportInfo = $this->extractArray($draftPayload, 'REPORT_INFO');
				$reportData = $this->extractArray($draftPayload, 'REPORT_DATA');
			}
		}

		$info = $this->extractArray($reportData ?? [], 'INFO');
		$fromUser = $this->extractArray($reportData ?? [], 'FROM');
		$reportId = $this->extractInt($reportData, 'REPORT_ID') ?? $this->extractInt($info, 'REPORT_ID') ?? 0;

		return [
			'id' => $reportId,
			'userId' => $this->extractInt($fromUser, 'ID') ?? $userId,
			'reportType' => $this->normalizeReportType($reportInfo),
			'reportDate' => $this->extractInt($reportInfo, 'REPORT_DATE_SUBMIT'),
			'dateFrom' => $this->extractInt($info, 'REPORT_DATE_FROM'),
			'dateTo' => $this->extractInt($info, 'REPORT_DATE_TO'),
			'report' => $this->extractString($reportData, 'REPORT'),
			'reportExtended' => $this->extractString($reportData, 'REPORT_EXTENDED'),
			'type' => RecordReportType::normalize($this->extractString($reportData, 'TYPE')),
			'plans' => $this->extractString($reportData, 'PLANS'),
			'tasks' => $this->extractArray($info ?? [], 'TASKS'),
			'events' => $this->extractArray($info ?? [], 'EVENTS'),
			'files' => $this->extractArray($info ?? [], 'FILES'),
			'fromUser' => $fromUser,
			'toUsers' => $this->extractList($reportData ?? [], 'TO'),
			'isReadyForSubmit' => !empty($reportData),
		];
	}

	private function loadLegacyPayload(int $userId, bool $force): array
	{
		$payload = (new \CUserReportFull($userId))->GetReportData($force, true);

		return is_array($payload) ? $payload : [];
	}

	private function loadDraftPayload(int $userId): ?array
	{
		$draft = $this->fullReportRepository->findLatestDraft($userId);
		if ($draft === null)
		{
			return null;
		}

		return [
			'REPORT_INFO' => [
				'REPORT_DATE_SUBMIT' => $draft->reportDate,
			],
			'REPORT_DATA' => [
				'REPORT_ID' => $draft->id,
				'REPORT' => $draft->report ?? '',
				'REPORT_EXTENDED' => $draft->reportExtended ?? '',
				'TYPE' => RecordReportType::normalize($draft->type),
				'PLANS' => $draft->plans ?? '',
				'FROM' => ['ID' => $draft->userId],
				'INFO' => [
					'REPORT_DATE_FROM' => $draft->dateFrom,
					'REPORT_DATE_TO' => $draft->dateTo,
				],
			],
		];
	}

	private function extractArray(array $payload, string $key): ?array
	{
		$value = $payload[$key] ?? null;

		return is_array($value) ? $value : null;
	}

	private function extractList(array $payload, string $key): ?array
	{
		$value = $payload[$key] ?? null;
		if (!is_array($value))
		{
			return null;
		}

		return array_values($value);
	}

	private function extractString(?array $payload, string $key): ?string
	{
		if (!is_array($payload))
		{
			return null;
		}

		$value = $payload[$key] ?? null;

		return is_string($value) ? $value : null;
	}

	private function extractInt(?array $payload, string $key): ?int
	{
		if (!is_array($payload))
		{
			return null;
		}

		$value = $payload[$key] ?? null;
		if (!is_numeric($value))
		{
			return null;
		}

		return (int)$value;
	}

	private function normalizeReportType(?array $payload): ?string
	{
		$reportType = $this->extractString($payload, 'REPORT_TYPE');
		if ($reportType === null || $reportType === '')
		{
			return null;
		}

		return mb_strtolower($reportType);
	}
}
