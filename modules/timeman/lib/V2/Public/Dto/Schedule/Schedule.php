<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Dto\Schedule;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Timeman\V2\Internal\Entity\Schedule\Schedule as ScheduleEntity;
use Bitrix\Timeman\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Timeman\V2\Internal\Repository\Mapper\ScheduleMapper;

final class Schedule implements Arrayable
{
	use MapTypeTrait;

	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $type,
	)
	{
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function isFixed(): bool
	{
		return ScheduleMapper::normalizeType($this->type) === ScheduleEntity::TYPE_FIXED;
	}

	public function isShifted(): bool
	{
		return ScheduleMapper::normalizeType($this->type) === ScheduleEntity::TYPE_SHIFT;
	}

	public function isFlextime(): bool
	{
		return ScheduleMapper::normalizeType($this->type) === ScheduleEntity::TYPE_FLEXTIME;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id: static::mapInteger($props, 'id', 0),
			name: static::mapString($props, 'name', ''),
			type: ScheduleMapper::normalizeType(static::mapString($props, 'type', '')),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'type' => $this->type,
		];
	}
}
