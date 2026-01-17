<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\Entity\Priority;
use Bitrix\Tasks\V2\Internal\Entity\Task\Status;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Trait\MapTimestampTrait;

class CreateTaskDto
{
	use MapTypeTrait;
	use MapTimestampTrait;

	private function __construct(
		#[NotEmpty]
		public readonly ?string $title = null,
		public readonly ?string $description = null,
		#[PositiveNumber]
		public readonly ?int $creatorId = null,
		#[PositiveNumber]
		public readonly ?int $responsibleId = null,
		public readonly ?int $deadlineTs = null,
		public readonly ?int $groupId = null,
		public readonly ?Priority $priority = null,
		public readonly ?Status $status = null,
		public readonly ?array $accompliceIds = null,
		public readonly ?array $auditorIds = null,
		public readonly ?int $parentId = null,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		$userId = static::mapInteger($props, 'userId');

		if (!isset($props['creatorId']))
		{
			$props['creatorId'] = $userId;
		}

		if (!isset($props['responsibleId']))
		{
			$props['responsibleId'] = $userId;
		}

		return new static(
			title: static::mapString($props, 'title'),
			description: static::mapString($props, 'description'),
			creatorId: static::mapInteger($props, 'creatorId'),
			responsibleId: static::mapInteger($props, 'responsibleId'),
			deadlineTs: static::mapTimestampWithTimeZone($props, 'deadlineDate'),
			groupId: static::mapInteger($props, 'groupId'),
			priority: static::mapBackedEnum($props, 'priority', Priority::class),
			status: static::mapBackedEnum($props, 'status', Status::class),
			accompliceIds: static::mapArray($props, 'accompliceIds', 'intval'),
			auditorIds: static::mapArray($props, 'auditorIds', 'intval'),
			parentId: static::mapInteger($props, 'parentTaskId'),
		);
	}
}
