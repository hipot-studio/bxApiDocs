<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Task;

use Bitrix\Tasks\V2\Internal\Entity\AbstractEntity;

class Timer extends AbstractEntity
{
	public function __construct(
		public readonly ?int $userId = null,
		public readonly ?int $taskId = null,
		public readonly ?int $startedAtTs = null,
		public readonly ?int $seconds = null,
	)
	{

	}

	public function getId(): ?int
	{
		// for this entity userId is primary
		return $this->userId;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			userId: $props['userId'] ?? null,
			taskId: $props['taskId'] ?? null,
			startedAtTs: $props['startedAtTs'] ?? null,
			seconds: $props['seconds'] ?? null,
		);
	}

	public function toArray(): array
	{
		return [
			'userId' => $this->userId,
			'taskId' => $this->taskId,
			'startedAtTs' => $this->startedAtTs,
			'seconds' => $this->seconds,
		];
	}
}