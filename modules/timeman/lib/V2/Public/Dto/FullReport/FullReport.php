<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Dto\FullReport;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Timeman\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Timeman\V2\Public\Dto\User\User;
use Bitrix\Timeman\V2\Public\Dto\User\UserCollection;

final class FullReport implements Arrayable
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
		public readonly ?User $fromUser = null,
		public readonly ?UserCollection $toUsers = null,
		public readonly ?bool $isReadyForSubmit = null,
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
			fromUser: self::mapUser($props['fromUser'] ?? null),
			toUsers: self::mapUsers($props['toUsers'] ?? null),
			isReadyForSubmit: static::mapBool($props, 'isReadyForSubmit'),
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
