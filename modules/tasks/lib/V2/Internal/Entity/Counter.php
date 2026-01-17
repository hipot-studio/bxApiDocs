<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class Counter extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?int $id = null,
		public readonly ?int $userId = null,
		public readonly ?int $taskId = null,
		public readonly ?int $groupId = null,
		public readonly ?string $type = null,
		public readonly ?int $value = null,
	)
	{
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'taskId' => $this->taskId,
			'groupId' => $this->groupId,
			'type' => $this->type,
			'value' => $this->value,
		];
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			self::mapInteger($props, 'id'),
			self::mapInteger($props, 'userId'),
			self::mapInteger($props, 'taskId'),
			self::mapInteger($props, 'groupId'),
			self::mapString($props, 'type'),
			self::mapInteger($props, 'value'),
		);
	}
}
