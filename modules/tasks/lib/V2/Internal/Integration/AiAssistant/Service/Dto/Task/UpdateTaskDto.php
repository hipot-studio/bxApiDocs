<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\Entity\Priority;
use Bitrix\Tasks\V2\Internal\Entity\Task\Status;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Trait\MapTimestampTrait;

class UpdateTaskDto
{
	use MapTypeTrait;
	use MapTimestampTrait;

	private function __construct(
		#[PositiveNumber]
		public readonly ?int $taskId = null,
		public readonly ?string $title = null,
		public readonly ?string $description = null,
		public readonly ?int $creatorId = null,
		public readonly ?int $responsibleId = null,
		public readonly ?int $deadlineTs = null,
		public readonly ?int $groupId = null,
		public readonly ?Priority $priority = null,
		public readonly ?Status $status = null,
		public readonly ?int $parentId = null,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new static(
			taskId: static::mapInteger($props, 'taskId'),
			title: static::mapString($props, 'title'),
			description: static::mapString($props, 'description'),
			creatorId: static::mapInteger($props, 'creatorId'),
			responsibleId: static::mapInteger($props, 'responsibleId'),
			deadlineTs: static::mapDeadlineTimestamp($props),
			groupId: static::mapInteger($props, 'groupId'),
			priority: static::mapBackedEnum($props, 'priority', Priority::class),
			status: static::mapBackedEnum($props, 'status', Status::class),
			parentId: static::mapInteger($props, 'parentTaskId'),
		);
	}

	public function isEmpty(): bool
	{
		return
			$this->title === null
			&& $this->description === null
			&& $this->creatorId === null
			&& $this->responsibleId === null
			&& $this->deadlineTs === null
			&& $this->groupId === null
			&& $this->priority === null
			&& $this->status === null
			&& $this->parentId === null
		;
	}

	private static function mapDeadlineTimestamp(array $props): ?int
	{
		$deadlineDate = $props['deadlineDate'] ?? null;

		if ($deadlineDate === '')
		{
			return 0;
		}

		return static::mapTimestampWithTimeZone($props, 'deadlineDate');
	}
}
