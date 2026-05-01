<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Task;

use Bitrix\Tasks\V2\Internal\Entity\AbstractEntity;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class AccessRequest extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?int $taskId = null,
		public readonly ?int $userId = null,
		public readonly ?int $createdDateTs = null,
	)
	{

	}
	public function getId(): array
	{
		return [
			'taskId' => $this->taskId,
			'userId' => $this->userId,
		];
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			taskId: static::mapInteger($props, 'taskId'),
			userId: static::mapInteger($props, 'userId'),
			createdDateTs: static::mapInteger($props, 'createdDateTs'),
		);
	}

	public function toArray(): array
	{
		return [
			'taskId' => $this->taskId,
			'userId' => $this->userId,
			'createdDateTs' => $this->createdDateTs,
		];
	}
}
