<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Entity\Record;

/**
 * Internal input for actions with worktime record.
 */
final class RecordForm
{
	public function __construct(
		public readonly int $userId,
		public readonly ?int $scheduleId = null,
		public readonly ?int $shiftId = null,
		public readonly ?int $recordId = null,
		public readonly ?string $reason = null,
		public readonly ?int $stopTimestamp = null,
		public readonly array $tasks = [],
		public readonly ?string $ipOpen = null,
		public readonly ?string $ipClose = null,
		public readonly ?float $latitudeOpen = null,
		public readonly ?float $longitudeOpen = null,
		public readonly ?float $latitudeClose = null,
		public readonly ?float $longitudeClose = null,
		public readonly ?string $device = null,
	)
	{
	}
}
