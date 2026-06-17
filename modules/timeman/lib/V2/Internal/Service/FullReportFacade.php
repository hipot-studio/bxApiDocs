<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Service;

final class FullReportFacade
{
	public function getReportToSend(int $userId, bool $force = false): array
	{
		$legacyPayload = $this->loadLegacyPayload($userId, $force);
		$reportData = $this->extractArray($legacyPayload, 'REPORT_DATA');
		$info = $this->extractArray($reportData ?? [], 'INFO');
		$fromUser = $this->extractArray($reportData ?? [], 'FROM');
		$reportId = $this->extractInt($reportData, 'REPORT_ID') ?? $this->extractInt($info, 'REPORT_ID') ?? 0;

		return [
			'id' => $reportId,
			'userId' => $this->extractInt($fromUser, 'ID') ?? $userId,
			'dateFrom' => $this->extractInt($info, 'REPORT_DATE_FROM'),
			'dateTo' => $this->extractInt($info, 'REPORT_DATE_TO'),
			'report' => $this->extractString($reportData, 'REPORT'),
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
		$payload = (new \CUserReportFull($userId))->GetReportData($force);

		return is_array($payload) ? $payload : [];
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
}
