<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

#[Recipients(creator: false, responsible: true, accomplices: true, auditors: false)]
class NotifyChecklistItemsUnchecked extends AbstractNotify
{
	public function __construct(
		private readonly Entity\Task $task,
		MessageSenderInterface $sender,
		protected readonly ?Entity\User $triggeredBy = null,
		private readonly int $itemCount = 1,
		private readonly string $checklistName = '',
		private readonly ?int $checkListId = null,
		private readonly array $itemIds = [],
	)
	{
		$sender->sendMessage(task: $task, notification: $this);
	}

	public function toString(): string
	{
		return $this->toPluralString($this->itemCount);
	}

	public function getMessageCode(): string
	{
		return match ($this->triggeredBy?->getGender()) {
			Entity\User\Gender::Male   => 'TASKS_IM_CHECKLIST_ITEMS_UNCHECKED_M',
			Entity\User\Gender::Female => 'TASKS_IM_CHECKLIST_ITEMS_UNCHECKED_F',
			default                    => 'TASKS_IM_CHECKLIST_ITEMS_UNCHECKED_M',
		};
	}

	public function getMessageData(): array
	{
		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#ITEM_COUNT#' => $this->itemCount,
			'#CHECKLIST_NAME#' => $this->checklistName,
		];
	}
}
