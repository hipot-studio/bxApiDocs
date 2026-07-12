<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

#[Recipients(creator: false, responsible: true, accomplices: true, auditors: false)]
class NotifyChecklistFilesAdded extends AbstractNotify
{
	public function __construct(
		private readonly Entity\Task $task,
		MessageSenderInterface $sender,
		protected readonly ?Entity\User $triggeredBy = null,
		private readonly ChatActionLinkService $chatActionLinkService,
		private readonly int $fileCount = 1,
		private readonly string $checklistName = '',
		private readonly ?int $checkListId = null,
		private readonly array $itemIds = [],
	)
	{
		$sender->sendMessage(task: $task, notification: $this);
	}

	public function toString(): string
	{
		return $this->toPluralString($this->fileCount);
	}

	public function getMessageCode(): string
	{
		return match ($this->triggeredBy?->getGender()) {
			Entity\User\Gender::Male   => 'TASKS_IM_CHECKLIST_FILES_ADDED_M_MSGVER_1',
			Entity\User\Gender::Female => 'TASKS_IM_CHECKLIST_FILES_ADDED_F_MSGVER_1',
			default                    => 'TASKS_IM_CHECKLIST_FILES_ADDED_M_MSGVER_1',
		};
	}

	public function getMessageData(): array
	{
		$actionLink = $this->chatActionLinkService->get(
			task: $this->task,
			userId: (int)$this->triggeredBy?->getId(),
			action: ChatAction::ShowCheckListItems,
			entityId: (int)$this->checkListId,
			params: [
				ChatActionParam::ChildrenIds->value => $this->itemIds,
			],
		);

		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#FILES_COUNT#' => $this->fileCount,
			'#CHECKLIST_NAME#' => $this->checklistName,
			'#ACTION_LINK#' => $actionLink,
		];
	}
}
