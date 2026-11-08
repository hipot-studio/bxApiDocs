<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Dto\Record;

use Bitrix\Main\Type\Contract\Arrayable;

final readonly class CurrentState implements Arrayable
{
	public const STATUS_OPENED = 'opened';
	public const STATUS_PAUSED = 'paused';
	public const STATUS_CLOSED = 'closed';
	public const STATUS_NONE = 'none';
	public const STATUS_UNKNOWN = 'unknown';

	public const SCHEDULE_TYPE_FIXED = 'fixed';
	public const SCHEDULE_TYPE_SHIFT = 'shift';
	public const SCHEDULE_TYPE_FLEX = 'flex';
	public const SCHEDULE_TYPE_UNKNOWN = 'unknown';

	public function __construct(
		public int $userId,
		public bool $trusted,
		public ?int $recordId,
		public string $status,
		public ?int $startTimestamp,
		public ?int $stopTimestamp,
		public string $scheduleType,
		public bool $automaticConfigurationTrusted,
		public bool $automaticConfigured,
		public string $automaticMode,
	)
	{
	}

	public function getId(): int
	{
		return $this->userId;
	}

	public function toArray(): array
	{
		return [
			'userId' => $this->userId,
			'trusted' => $this->trusted,
			'recordId' => $this->recordId,
			'status' => $this->status,
			'startTimestamp' => $this->startTimestamp,
			'stopTimestamp' => $this->stopTimestamp,
			'scheduleType' => $this->scheduleType,
			'automaticConfigurationTrusted' => $this->automaticConfigurationTrusted,
			'automaticConfigured' => $this->automaticConfigured,
			'automaticMode' => $this->automaticMode,
		];
	}
}
