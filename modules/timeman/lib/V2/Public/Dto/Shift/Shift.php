<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Dto\Shift;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Main\Type\DateTime;
use Bitrix\Timeman\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Timeman\V2\Public\Dto\Schedule\Schedule;

final class Shift implements Arrayable
{
	use MapTypeTrait;

	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly int $workTimeStart,
		public readonly int $workTimeEnd,
		public readonly ?DateTime $start = null,
		public readonly ?DateTime $stop = null,
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
		return new static(
			id: static::mapInteger($props, 'id', 0),
			name: static::mapString($props, 'name', ''),
			workTimeStart: static::mapInteger($props, 'workTimeStart', 0),
			workTimeEnd: static::mapInteger($props, 'workTimeEnd', 0),
			start: self::mapFlexibleDateTime($props['start'] ?? null),
			stop: self::mapFlexibleDateTime($props['stop'] ?? null),
			schedule: self::mapSchedule($props['schedule'] ?? null),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'workTimeStart' => $this->workTimeStart,
			'workTimeEnd' => $this->workTimeEnd,
			'start' => $this->start?->format(DateTime::getFormat()),
			'stop' => $this->stop?->format(DateTime::getFormat()),
			'schedule' => $this->schedule?->toArray(),
		];
	}

	private static function mapFlexibleDateTime(mixed $value): ?DateTime
	{
		if ($value instanceof DateTime)
		{
			return $value;
		}

		if (!is_string($value) || $value === '')
		{
			return null;
		}

		try
		{
			return new DateTime($value, DateTime::getFormat());
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private static function mapSchedule(mixed $schedule): ?Schedule
	{
		if ($schedule instanceof Schedule)
		{
			return $schedule;
		}

		if ($schedule instanceof \Bitrix\Timeman\V2\Internal\Entity\Schedule\Schedule)
		{
			return Schedule::mapFromArray(get_object_vars($schedule));
		}

		return is_array($schedule) ? Schedule::mapFromArray($schedule) : null;
	}
}
