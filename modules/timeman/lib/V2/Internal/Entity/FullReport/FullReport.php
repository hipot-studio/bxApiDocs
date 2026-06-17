<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Entity\FullReport;

use Bitrix\Timeman\V2\Internal\Entity\AbstractEntity;
use Bitrix\Timeman\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Timeman\V2\Internal\Entity\FullReport\FullReportApprove;

class FullReport extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly int $id,
		public readonly int $userId,
		public readonly ?bool $active,
		public readonly ?int $reportDate,
		public readonly ?int $dateFrom,
		public readonly ?int $dateTo,
		public readonly ?string $report,
		public readonly ?string $plans,
		public readonly ?array $tasks,
		public readonly ?array $events,
		public readonly ?array $files,
		public readonly ?string $mark,
		public readonly ?string $approve,
		public readonly ?int $approveDate,
		public readonly ?int $approver,
		public readonly ?int $forumTopicId,
		public readonly ?int $timestamp,
	)
	{
	}

	public function getId(): int
	{
		return $this->id;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id: static::mapInteger($props, 'id', 0) ?? 0,
			userId: static::mapInteger($props, 'userId', 0) ?? 0,
			active: (bool)($props['active'] ?? false),
			reportDate: static::mapInteger($props, 'reportDate'),
			dateFrom: static::mapInteger($props, 'dateFrom'),
			dateTo: static::mapInteger($props, 'dateTo'),
			report: static::mapString($props, 'report', '') ?? '',
			plans: static::mapString($props, 'plans'),
			tasks: static::mapArray($props, 'tasks'),
			events: static::mapArray($props, 'events'),
			files: static::mapArray($props, 'files'),
			mark: static::mapString($props, 'mark'),
			approve: static::mapString($props, 'approve'),
			approveDate: static::mapInteger($props, 'approveDate'),
			approver: static::mapInteger($props, 'approver'),
			forumTopicId: static::mapInteger($props, 'forumTopicId', 0) ?? 0,
			timestamp: static::mapInteger($props, 'timestamp'),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'active' => $this->active,
			'reportDate' => $this->reportDate,
			'dateFrom' => $this->dateFrom,
			'dateTo' => $this->dateTo,
			'report' => $this->report,
			'plans' => $this->plans,
			'tasks' => $this->tasks,
			'events' => $this->events,
			'files' => $this->files,
			'mark' => $this->mark,
			'approve' => $this->approve,
			'approveDate' => $this->approveDate,
			'approver' => $this->approver,
			'forumTopicId' => $this->forumTopicId,
			'timestamp' => $this->timestamp,
		];
	}

	/**
	 * @param array<string, mixed> $changes
	 */
	public function withChanges(array $changes): self
	{
		$reportText = array_key_exists('reportText', $changes)
			? static::mapString($changes, 'reportText', $this->report)
			: $this->report;
		$plansText = array_key_exists('plansText', $changes)
			? static::mapString($changes, 'plansText')
			: $this->plans;
		$dateFrom = array_key_exists('dateFrom', $changes)
			? static::mapInteger($changes, 'dateFrom')
			: $this->dateFrom;
		$dateTo = array_key_exists('dateTo', $changes)
			? static::mapInteger($changes, 'dateTo')
			: $this->dateTo;
		$tasks = array_key_exists('tasks', $changes)
			? static::mapArray($changes, 'tasks')
			: $this->tasks;
		$events = array_key_exists('events', $changes)
			? static::mapArray($changes, 'events')
			: $this->events;
		$files = array_key_exists('files', $changes)
			? static::mapArray($changes, 'files')
			: $this->files;
		$mark = array_key_exists('mark', $changes)
			? $this->resolveMark(static::mapString($changes, 'mark'))
			: $this->mark;
		$approve = array_key_exists('approve', $changes)
			? $this->resolveApprove(static::mapString($changes, 'approve'))
			: $this->approve;
		$approveDate = array_key_exists('approveDate', $changes)
			? static::mapInteger($changes, 'approveDate')
			: $this->approveDate;
		$approver = array_key_exists('approver', $changes)
			? static::mapInteger($changes, 'approver')
			: $this->approver;

		return new self(
			id: $this->id,
			userId: $this->userId,
			active: $this->active,
			reportDate: $this->reportDate,
			dateFrom: $dateFrom,
			dateTo: $dateTo,
			report: $reportText ?? '',
			plans: $plansText,
			tasks: $tasks,
			events: $events,
			files: $files,
			mark: $mark,
			approve: $approve,
			approveDate: $approveDate,
			approver: $approver,
			forumTopicId: $this->forumTopicId,
			timestamp: $this->timestamp,
		);
	}

	private function resolveMark(?string $mark): ?string
	{
		if ($mark === null)
		{
			return $this->mark;
		}

		return FullReportMark::tryFrom($mark)?->value ?? $this->mark;
	}

	private function resolveApprove(?string $approve): ?string
	{
		if ($approve === null)
		{
			return $this->approve;
		}

		return FullReportApprove::tryFrom($approve)?->value ?? $this->approve;
	}
}
