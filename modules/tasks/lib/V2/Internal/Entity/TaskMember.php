<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class TaskMember extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		#[PositiveNumber]
		public readonly ?int $taskId = null,
		public readonly ?int $userId = null,
		public readonly ?string $type = null,
	)
	{
	}

	public function getId(): ?int
	{
		return $this->taskId;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			taskId: static::mapInteger($props, 'taskId'),
			userId: static::mapInteger($props, 'userId'),
			type: static::mapString($props, 'type'),
		);
	}

	public function toArray(): array
	{
		return [
			'taskId' => $this->taskId,
			'userId' => $this->userId,
			'type' => $this->type,
		];
	}
}
