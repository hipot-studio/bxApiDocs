<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Task;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\Entity\AbstractEntity;
use Bitrix\Tasks\V2\Internal\Entity\Task\Reminder\Recipient;
use Bitrix\Tasks\V2\Internal\Entity\Task\Reminder\RemindBy;
use Bitrix\Tasks\V2\Internal\Entity\Task\Reminder\RemindVia;

class Reminder extends AbstractEntity
{
	public function __construct(
		public readonly ?int $id = null,
		#[PositiveNumber]
		public readonly ?int $userId = null,
		#[PositiveNumber]
		public readonly ?int $taskId = null,
		// #[PositiveNumber]
		public readonly ?int $nextRemindTs = null,
		public readonly ?RemindBy $remindBy = null,
		public readonly ?RemindVia $remindVia = null,
		public readonly ?Recipient $recipient = null,
		public readonly ?array $rrule = null,
		public readonly ?int $before = null,
	)
	{

	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id: $props['id'] ?? null,
			userId: $props['userId'] ?? null,
			taskId: $props['taskId'] ?? null,
			nextRemindTs: $props['nextRemindTs'] ?? null,
			remindBy: isset($props['remindBy']) ? RemindBy::tryFrom($props['remindBy']) : null,
			remindVia: isset($props['remindVia']) ? RemindVia::tryFrom($props['remindVia']) : null,
			recipient: isset($props['recipient']) ? Recipient::tryFrom($props['recipient']) : null,
			rrule: $props['rrule'] ?? null,
			before: $props['before'] ?? null,
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'taskId' => $this->taskId,
			'nextRemindTs' => $this->nextRemindTs,
			'remindBy' => $this->remindBy?->value,
			'remindVia' => $this->remindVia?->value,
			'recipient' => $this->recipient?->value,
			'rrule' => $this->rrule,
			'before' => $this->before,
		];
	}
}