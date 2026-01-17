<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Role;

#[Recipients(creator: false, responsible: true, accomplices: true, auditors: false)]
class NotifyChecklistSingleItemUnchecked extends AbstractNotify
{
	public function __construct(
		private readonly Entity\Task $task,
		MessageSenderInterface $sender,
		protected readonly ?Entity\User $triggeredBy = null,
		private readonly string $checklistName = '',
		private readonly string $itemName = '',
		private readonly ?int $checkListId = null,
		private readonly array $itemIds = [],
	)
	{
		$sender->sendMessage(task: $task, notification: $this);
	}

	public function getRoles(): array
	{
		return [Role::Responsible, Role::Accomplice];
	}

	public function getMessageCode(): string
	{
		return match ($this->triggeredBy?->getGender()) {
			Entity\User\Gender::Male   => 'TASKS_IM_CHECKLIST_SINGLE_ITEM_UNCHECKED_M',
			Entity\User\Gender::Female => 'TASKS_IM_CHECKLIST_SINGLE_ITEM_UNCHECKED_F',
			default                    => 'TASKS_IM_CHECKLIST_SINGLE_ITEM_UNCHECKED_M',
		};
	}

	public function getMessageData(): array
	{
		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#ITEM_NAME#' => $this->itemName,
			'#CHECKLIST_NAME#' => $this->checklistName,
		];
	}
}
