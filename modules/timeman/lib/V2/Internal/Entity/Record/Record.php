<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Entity\Record;

use Bitrix\Timeman\V2\Internal\Entity\AbstractEntity;
use Bitrix\Timeman\V2\Internal\Entity\Schedule\Schedule;
use Bitrix\Timeman\V2\Internal\Entity\Shift\Shift;
use Bitrix\Timeman\V2\Internal\Entity\Trait\MapTypeTrait;

final class Record extends AbstractEntity
{
	use MapTypeTrait;
	use RecordStateMapTrait;

	public function __construct(
		public readonly int $id,
		public readonly int $userId,
		public readonly ?int $startTime,
		public readonly ?int $endTime,
		public readonly ?int $duration,
		public readonly ?int $breakLength,
		public readonly RecordState $state,
		public readonly bool $isApproved,
		public readonly ?Shift $shift = null,
		public readonly ?Schedule $schedule = null,
	)
	{
	}

	public function getId(): int
	{
		return $this->id;
	}

	public static function mapFromArray(array $props): static
	{
		$state = self::mapState($props);
		$shift = self::mapShift($props);
		$schedule = self::mapSchedule($props);

		return new static(
			id: static::mapInteger($props, 'id', 0) ?? 0,
			userId: static::mapInteger($props, 'userId', 0) ?? 0,
			startTime: static::mapInteger($props, 'startTime'),
			endTime: static::mapInteger($props, 'endTime'),
			duration: static::mapInteger($props, 'duration'),
			breakLength: static::mapInteger($props, 'breakLength'),
			state: $state,
			isApproved: (bool)($props['isApproved'] ?? false),
			shift: $shift,
			schedule: $schedule,
		);
	}

	private static function mapShift(array $props): ?Shift
	{
		return isset($props['shift']) && is_array($props['shift'])
			? Shift::mapFromArray($props['shift'])
			: null;
	}

	private static function mapSchedule(array $props): ?Schedule
	{
		return isset($props['schedule']) && is_array($props['schedule'])
			? Schedule::mapFromArray($props['schedule'])
			: null;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'startTime' => $this->startTime,
			'endTime' => $this->endTime,
			'duration' => $this->duration,
			'breakLength' => $this->breakLength,
			'state' => $this->state->toArray(),
			'isApproved' => $this->isApproved,
			'shift' => $this->shift?->toArray(),
			'schedule' => $this->schedule?->toArray(),
		];
	}
}

