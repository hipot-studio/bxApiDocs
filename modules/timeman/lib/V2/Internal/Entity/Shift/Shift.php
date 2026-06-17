<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Entity\Shift;

use Bitrix\Main\Type\DateTime;
use Bitrix\Timeman\V2\Internal\Entity\Schedule\Schedule;
use Bitrix\Timeman\V2\Internal\Entity\AbstractEntity;
use Bitrix\Timeman\V2\Internal\Entity\Trait\MapTypeTrait;

class Shift extends AbstractEntity
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
			start: static::mapDateTime($props, 'start'),
			stop: static::mapDateTime($props, 'stop'),
			schedule: (isset($props['schedule']) && is_array($props['schedule']))
				? Schedule::mapFromArray($props['schedule'])
				: null,
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
}
