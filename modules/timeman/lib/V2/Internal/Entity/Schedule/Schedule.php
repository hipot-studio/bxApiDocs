<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Entity\Schedule;

use Bitrix\Timeman\V2\Internal\Entity\AbstractEntity;
use Bitrix\Timeman\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Timeman\V2\Internal\Repository\Mapper\ScheduleMapper;

class Schedule extends AbstractEntity
{
	use MapTypeTrait;

	public const TYPE_FIXED = 'fixed';
	public const TYPE_SHIFT = 'shift';
	public const TYPE_FLEXTIME = 'flextime';

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
