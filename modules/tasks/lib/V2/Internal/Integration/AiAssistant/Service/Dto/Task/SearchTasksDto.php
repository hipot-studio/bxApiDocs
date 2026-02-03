<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Trait\MapDateTimeTrait;

class SearchTasksDto
{
	use MapTypeTrait;
	use MapDateTimeTrait;

	private function __construct(
		public readonly ?string $title = null,
		public readonly ?string $description = null,
		public readonly ?DateTime $deadlineFrom = null,
		public readonly ?DateTime $deadlineTo = null,
		public readonly ?int $groupId = null,
		public readonly ?int $responsibleId = null,
		public readonly ?int $creatorId = null,
		public readonly ?int $memberId = null,
		public readonly ?int $auditorId = null,
		public readonly ?int $accompliceId = null,
		public readonly ?string $tag = null,
		public readonly ?Entity\Task\Status $status = null,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			title: static::mapString($props, 'title'),
			description: static::mapString($props, 'description'),
			deadlineFrom: static::mapFormattedDateTime($props, 'deadlineFrom'),
			deadlineTo: static::mapFormattedDateTime($props, 'deadlineTo'),
			groupId: static::mapInteger($props, 'groupId'),
			responsibleId: static::mapInteger($props, 'responsibleId'),
			creatorId: static::mapInteger($props, 'creatorId'),
			memberId: static::mapInteger($props, 'memberId'),
			auditorId: static::mapInteger($props, 'auditorId'),
			accompliceId: static::mapInteger($props, 'accompliceId'),
			tag: static::mapString($props, 'tag'),
			status: static::mapBackedEnum($props, 'status', Entity\Task\Status::class),
		);
	}

	public function hasParticipantFilters(): bool
	{
		return
			$this->memberId !== null
			|| $this->creatorId !== null
			|| $this->responsibleId !== null
			|| $this->accompliceId !== null
			|| $this->auditorId !== null
		;
	}
}
