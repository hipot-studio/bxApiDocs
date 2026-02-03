<?php

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Im\V2\Message\Params;
use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

#[Recipients(creator: true, responsible: true, accomplices: true, auditors: false)]
class NotifyResultFromMessage extends AbstractNotify
{
	public function __construct(
		private readonly Entity\Task $task,
		MessageSenderInterface $sender,
		protected readonly ?Entity\User $triggeredBy = null,
		private readonly int $messageId = 0,
		private readonly int $dateTs = 0,
		private readonly ?Entity\Result\Type $type = null,
	)
	{
		$sender->sendMessage(task: $task, notification: $this);
	}

	public function getMessageCode(): string
	{
		return $this->triggeredBy?->getGender() === Entity\User\Gender::Female
			? 'TASKS_IM_RESULT_FROM_MESSAGE_F'
			: 'TASKS_IM_RESULT_FROM_MESSAGE_M'
		;
	}

	public function getMessageData(): array
	{
		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#DATE#' => "[TIMESTAMP=$this->dateTs FORMAT=LONG_DATE_FORMAT]",
		];
	}

	public function getMessageParams(): array
	{
		if (
			$this->type !== Entity\Result\Type::Ai
			|| !Loader::includeModule('im')
			// TODO: Soft dependence on im
			|| !defined('\Bitrix\Im\V2\Message\Params::AI_TASK_TRIGGER_MESSAGE_ID')
		)
		{
			return [];
		}

		return [Params::AI_TASK_TRIGGER_MESSAGE_ID => $this->messageId];
	}
}
