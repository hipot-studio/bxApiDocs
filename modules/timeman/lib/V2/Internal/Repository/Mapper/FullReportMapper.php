<?php

namespace Bitrix\Timeman\V2\Internal\Repository\Mapper;

use Bitrix\Main\Type\DateTime;
use Bitrix\Timeman\V2\Internal\Entity\FullReport\FullReport;
use Bitrix\Timeman\V2\Internal\Entity\FullReport\FullReportCollection;
use Bitrix\Timeman\V2\Internal\Entity\FullReport\FullReportApprove;
use Bitrix\Timeman\V2\Internal\Entity\FullReport\FullReportMark;

class FullReportMapper
{
	public function mapToCollection(array $reports): FullReportCollection
	{
		$entities = [];
		foreach ($reports as $report)
		{
			$entities[] = $this->mapToEntity($report);
		}

		return new FullReportCollection(...$entities);
	}

	public function mapToEntity(array $data): FullReport
	{
		$timestamp = ($data['TIMESTAMP_X'] instanceof DateTime) ? $data['TIMESTAMP_X']->getTimestamp() : null;
		$reportDate = ($data['REPORT_DATE'] instanceof DateTime) ? $data['REPORT_DATE']->getTimestamp() : null;
		$dateFrom = ($data['DATE_FROM'] instanceof DateTime) ? $data['DATE_FROM']->getTimestamp() : null;
		$dateTo = ($data['DATE_TO'] instanceof DateTime) ? $data['DATE_TO']->getTimestamp() : null;
		$approveDate = ($data['APPROVE_DATE'] instanceof DateTime) ? $data['APPROVE_DATE']->getTimestamp() : null;

		return new FullReport(
			id: (int)($data['ID'] ?? 0),
			userId: (int)($data['USER_ID'] ?? 0),
			active: (string)($data['ACTIVE'] ?? 'N') === 'Y',
			reportDate: $reportDate,
			dateFrom: $dateFrom,
			dateTo: $dateTo,
			report: (string)($data['REPORT'] ?? ''),
			plans: isset($data['PLANS']) ? (string)$data['PLANS'] : null,
			tasks: $this->deserializePayloadList($data['TASKS'] ?? null),
			events: $this->deserializePayloadList($data['EVENTS'] ?? null),
			files: $this->deserializePayloadList($data['FILES'] ?? null),
			mark: $this->normalizeMark($data['MARK'] ?? null),
			approve: $this->normalizeApprove($data['APPROVE'] ?? null),
			approveDate: $approveDate,
			approver: isset($data['APPROVER']) ? (int)$data['APPROVER'] : null,
			forumTopicId: (int)($data['FORUM_TOPIC_ID'] ?? 0),
			timestamp: $timestamp,
		);
	}

	public function mapFromEntity(FullReport $report, bool $forUpdate = false): array
	{
		$fields = [
			'USER_ID' => $report->userId,
			'ACTIVE' => $report->active ? 'Y' : 'N',
			'REPORT' => $report->report,
			'FORUM_TOPIC_ID' => $report->forumTopicId,
		];

		$this->setField($fields, 'REPORT_DATE', $this->dateTimeFromTimestamp($report->reportDate), $forUpdate);
		$this->setField($fields, 'DATE_FROM', $this->dateTimeFromTimestamp($report->dateFrom), $forUpdate);
		$this->setField($fields, 'DATE_TO', $this->dateTimeFromTimestamp($report->dateTo), $forUpdate);
		$this->setField($fields, 'PLANS', $report->plans, $forUpdate);
		$this->setField($fields, 'TASKS', $this->serializePayloadList($report->tasks), $forUpdate);
		$this->setField($fields, 'EVENTS', $this->serializePayloadList($report->events), $forUpdate);
		$this->setField($fields, 'FILES', $this->serializePayloadList($report->files), $forUpdate);
		$this->setField($fields, 'MARK', $this->normalizeMark($report->mark), $forUpdate);
		$this->setField($fields, 'APPROVE', $this->normalizeApprove($report->approve), $forUpdate);
		$this->setField($fields, 'APPROVE_DATE', $this->dateTimeFromTimestamp($report->approveDate), $forUpdate);
		$this->setField($fields, 'APPROVER', $report->approver, $forUpdate);
		$this->setField($fields, 'TIMESTAMP_X', $this->dateTimeFromTimestamp($report->timestamp), $forUpdate);

		return $fields;
	}

	private function dateTimeFromTimestamp(mixed $timestamp): ?DateTime
	{
		if ($timestamp === null)
		{
			return null;
		}

		$timestamp = (int)$timestamp;
		if ($timestamp <= 0)
		{
			return null;
		}

		return DateTime::createFromTimestamp($timestamp);
	}

	private function setField(array &$fields, string $key, mixed $value, bool $forUpdate): void
	{
		if ($forUpdate || $value !== null)
		{
			$fields[$key] = $value;
		}
	}

	private function normalizeMark(mixed $mark): ?string
	{
		if ($mark === null)
		{
			return null;
		}

		return FullReportMark::tryFrom((string)$mark)?->value;
	}

	private function normalizeApprove(mixed $approve): ?string
	{
		if ($approve === null)
		{
			return null;
		}

		return FullReportApprove::tryFrom((string)$approve)?->value;
	}

	private function deserializePayloadList(mixed $value): ?array
	{
		if (!is_string($value) || $value === '')
		{
			return null;
		}

		$result = @unserialize($value, ['allowed_classes' => false]);

		return is_array($result) ? $result : null;
	}

	private function serializePayloadList(?array $value): ?string
	{
		return is_array($value) ? serialize($value) : null;
	}
}
