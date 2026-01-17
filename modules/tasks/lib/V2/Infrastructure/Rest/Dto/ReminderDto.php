<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Tasks\V2\Internal\Entity\Task\Reminder;

class ReminderDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id = null;
	public ?int $userId = null;
	public ?int $taskId = null;
	public ?int $nextRemindTs = null;
	public ?string $remindBy = null;
	public ?string $remindVia = null;
	public ?string $recipient = null;
	public ?array $rrule = null;
	public ?int $before = null;

	public static function fromEntity(?Reminder $reminder, ?Request $request = null): ?self
	{
		if (!$reminder)
		{
			return null;
		}
		$select = $request?->select?->getList(true) ?? [];
		$dto = new self();
		if (empty($select) || in_array('id', $select, true))
		{
			$dto->id = $reminder->id;
		}
		if (empty($select) || in_array('userId', $select, true))
		{
			$dto->userId = $reminder->userId;
		}
		if (empty($select) || in_array('taskId', $select, true))
		{
			$dto->taskId = $reminder->taskId;
		}
		if (empty($select) || in_array('nextRemindTs', $select, true))
		{
			$dto->nextRemindTs = $reminder->nextRemindTs;
		}
		if (empty($select) || in_array('remindBy', $select, true))
		{
			$dto->remindBy = $reminder->remindBy?->value;
		}
		if (empty($select) || in_array('remindVia', $select, true))
		{
			$dto->remindVia = $reminder->remindVia?->value;
		}
		if (empty($select) || in_array('recipient', $select, true))
		{
			$dto->recipient = $reminder->recipient?->value;
		}
		if (empty($select) || in_array('rrule', $select, true))
		{
			$dto->rrule = $reminder->rrule;
		}
		if (empty($select) || in_array('before', $select, true))
		{
			$dto->before = $reminder->before;
		}

		return $dto;
	}
}
