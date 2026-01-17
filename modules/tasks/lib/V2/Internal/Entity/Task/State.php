<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Task;

use Bitrix\Tasks\Deadline\Entity\DeadlineUserOption;
use Bitrix\Tasks\V2\Internal\Entity\AbstractEntity;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class State extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?int $userId = null,
		public readonly ?DeadlineUserOption $defaultDeadline = null,
		public readonly ?bool $needsControl = null,
		public readonly ?bool $matchesWorkTime = null,
		public readonly ?bool $defaultRequireResult = null,
	)
	{
		if ($defaultDeadline)
		{
			$defaultDeadline->matchWorkTime = (bool)$this->matchesWorkTime;
		}
	}

	public function getId(): ?int
	{
		return $this->userId;
	}

	public function getFlags(): array
	{
		return [
			'needsControl' => $this->needsControl,
			'matchesWorkTime' => $this->matchesWorkTime,
			'defaultRequireResult' => $this->defaultRequireResult,
		];
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			userId: static::mapInteger($props, 'userId'),
			defaultDeadline: static::mapEntity($props, 'defaultDeadline', DeadlineUserOption::class),
			needsControl: static::mapBool($props, 'needsControl'),
			matchesWorkTime: static::mapBool($props, 'matchesWorkTime'),
			defaultRequireResult: static::mapBool($props, 'defaultRequireResult'),
		);
	}

	public function toArray(): array
	{
		return [
			'userId' => $this->userId,
			'deadlineUserOption' => $this->defaultDeadline?->toArray(),
			'needsControl' => $this->needsControl,
			'matchesWorkTime' => $this->matchesWorkTime,
			'defaultRequireResult' => $this->defaultRequireResult,
		];
	}
}
