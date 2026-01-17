<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

#[Recipients(creator: true, responsible: true, accomplices: true, auditors: false)]
class NotifyResultDeleted extends AbstractNotify
{
	public function __construct(
		private readonly Entity\Task $task,
		MessageSenderInterface $sender,
		protected readonly ?Entity\User $triggeredBy = null,
		private readonly int $dateTs = 0,
	)
	{
		$sender->sendMessage(task: $task, notification: $this);
	}

	public function getMessageCode(): string
	{
		return $this->triggeredBy?->getGender() === Entity\User\Gender::Female
			? 'TASKS_IM_RESULT_DELETED_MSGVER_1_F'
			: 'TASKS_IM_RESULT_DELETED_MSGVER_1_M'
		;
	}

	public function getMessageData(): array
	{
		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#DATE#' => "[TIMESTAMP=$this->dateTs FORMAT=LONG_DATE_FORMAT]",
		];
	}
}
