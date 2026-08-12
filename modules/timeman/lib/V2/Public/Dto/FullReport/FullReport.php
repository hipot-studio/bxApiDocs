<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Dto\FullReport;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Timeman\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Timeman\V2\Public\Dto\Report\RecordReportType;
use Bitrix\Timeman\V2\Public\Dto\User\User;
use Bitrix\Timeman\V2\Public\Dto\User\UserCollection;

final class FullReport implements Arrayable
{
	use MapTypeTrait;

	public function __construct(
		public readonly int $id,
		public readonly int $userId,
		public readonly ?bool $active,
		public readonly ?FullReportType $reportType,
		public readonly ?int $reportDate,
		public readonly ?int $dateFrom,
		public readonly ?int $dateTo,
		public readonly ?string $report,
		public readonly ?string $reportPlain,
		public readonly ?string $reportExtended,
		public readonly ?string $plans,
		public readonly ?array $tasks,
		public readonly ?array $events,
		public readonly ?array $files,
		public readonly ?string $mark,
		public readonly ?string $approve,
		public readonly ?User $fromUser = null,
		public readonly ?UserCollection $toUsers = null,
		public readonly ?bool $isReadyForSubmit = null,
		public readonly ?string $type = RecordReportType::REPORT,
	)
	{
	}

	public function getId(): int
	{
		return $this->id;
	}

	public static function mapFromArray(array $props): static
	{
		$dateFrom = self::mapInteger($props, 'dateFrom');
		$dateTo = self::mapInteger($props, 'dateTo');
		$reportType = (
			self::mapBackedEnum($props, 'reportType', FullReportType::class)
			?? FullReportType::resolveByPeriod($dateFrom, $dateTo)
		);

		return new self(
			id: self::mapInteger($props, 'id', 0) ?? 0,
			userId: self::mapInteger($props, 'userId', 0) ?? 0,
			active: (bool)($props['active'] ?? false),
			reportType: $reportType,
			reportDate: self::mapInteger($props, 'reportDate'),
			dateFrom: $dateFrom,
			dateTo: $dateTo,
			report: self::mapString($props, 'report', '') ?? '',
			reportPlain: self::mapPlainText($props, 'report', '') ?? '',
			reportExtended: self::mapString($props, 'reportExtended', '') ?? '',
			plans: self::mapString($props, 'plans'),
			tasks: self::mapArray($props, 'tasks'),
			events: self::mapArray($props, 'events'),
			files: self::mapArray($props, 'files'),
			mark: self::mapString($props, 'mark'),
			approve: self::mapString($props, 'approve'),
			fromUser: self::mapUser($props['fromUser'] ?? null),
			toUsers: self::mapUsers($props['toUsers'] ?? null),
			isReadyForSubmit: self::mapBool($props, 'isReadyForSubmit'),
			type: RecordReportType::normalize(self::mapString($props, 'type')),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'active' => $this->active,
			'reportType' => $this->reportType?->value,
			'reportDate' => $this->reportDate,
			'dateFrom' => $this->dateFrom,
			'dateTo' => $this->dateTo,
			'report' => $this->report,
			'reportPlain' => $this->reportPlain,
			'reportExtended' => $this->reportExtended,
			'type' => $this->type,
			'plans' => $this->plans,
			'tasks' => $this->tasks,
			'events' => $this->events,
			'files' => $this->files,
			'mark' => $this->mark,
			'approve' => $this->approve,
			'fromUser' => $this->fromUser?->toArray(),
			'toUsers' => $this->toUsers?->toArray(),
			'isReadyForSubmit' => $this->isReadyForSubmit,
		];
	}

	private static function mapUser(mixed $user): ?User
	{
		if ($user instanceof User)
		{
			return $user;
		}

		return is_array($user) ? User::mapFromArray($user) : null;
	}

	private static function mapUsers(mixed $users): ?UserCollection
	{
		if ($users instanceof UserCollection)
		{
			return $users;
		}

		return is_array($users) ? UserCollection::mapFromArray($users) : null;
	}

}
