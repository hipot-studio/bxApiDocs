<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

#[Recipients(creator: true, responsible: true, accomplices: true, auditors: false)]
class NotifyResultAdded extends AbstractNotifyResult
{
	public function __construct(
		private readonly Entity\Task $task,
		MessageSenderInterface $sender,
		private readonly ChatActionLinkService $chatActionLinkService,
		protected readonly ?Entity\User $triggeredBy = null,
		private readonly string $resultText = '',
		private readonly int $dateTs = 0,
		private readonly array $fileIds = [],
		private readonly int $resultId = 0,
	)
	{
		$sender->sendMessage(task: $task, notification: $this);
	}

	public function getMessageCode(): string
	{
		return $this->triggeredBy?->getGender() === Entity\User\Gender::Female
			? 'TASKS_IM_RESULT_ADDED_F_MSGVER_2'
			: 'TASKS_IM_RESULT_ADDED_M_MSGVER_2'
		;
	}

	public function getMessageData(): array
	{
		$actionLink = $this->chatActionLinkService->get(
			task: $this->task,
			userId: (int)$this->triggeredBy?->id,
			action: ChatAction::OpenResult,
			entityId: $this->resultId,
		);

		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#DATE#' => "[TIMESTAMP=$this->dateTs FORMAT=LONG_DATE_FORMAT]",
			'#OPEN_RESULT_URL#' => $actionLink,
		];
	}

	public function getAttach(): ?\CIMMessageParamAttach
	{
		return $this->buildResultTextAttach($this->resultText);
	}

	public function getTaskAttachIds(): array
	{
		return $this->fileIds;
	}
}
