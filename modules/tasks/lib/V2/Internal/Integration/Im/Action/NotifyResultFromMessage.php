<?php

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Im\V2\Message\Params;
use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

#[Recipients(creator: true, responsible: true, accomplices: true, auditors: false)]
class NotifyResultFromMessage extends AbstractNotifyResult
{
	public function __construct(
		private readonly Entity\Task $task,
		MessageSenderInterface $sender,
		private readonly ChatActionLinkService $chatActionLinkService,
		protected readonly ?Entity\User $triggeredBy = null,
		private readonly int $messageId = 0,
		private readonly int $dateTs = 0,
		private readonly int $resultId = 0,
		private readonly ?Entity\Result\Type $type = null,
		private readonly string $resultText = '',
	)
	{
		$sender->sendMessage(task: $task, notification: $this);
	}

	public function getMessageCode(): string
	{
		return $this->triggeredBy?->getGender() === Entity\User\Gender::Female
			? 'TASKS_IM_RESULT_FROM_MESSAGE_F_MSGVER_2'
			: 'TASKS_IM_RESULT_FROM_MESSAGE_M_MSGVER_2'
		;
	}

	public function getMessageData(): array
	{
		$openResultUrl = $this->chatActionLinkService->get(
			task: $this->task,
			userId: (int)$this->triggeredBy?->id,
			action: ChatAction::OpenResult,
			entityId: $this->resultId,
		);

		$chatId = $this->task->chatId;
		$hasSourceMessage = $this->messageId > 0 && $chatId !== null && $chatId > 0;

		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#DATE#' => "[TIMESTAMP=$this->dateTs FORMAT=LONG_DATE_FORMAT]",
			'#OPEN_RESULT_URL#' => $openResultUrl,
			'#SOURCE_MESSAGE_OPEN#' => $hasSourceMessage ? '[CONTEXT=chat' . $chatId . '/' . $this->messageId . ']' : '',
			'#SOURCE_MESSAGE_CLOSE#' => $hasSourceMessage ? '[/CONTEXT]' : '',
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

	public function getAttach(): ?\CIMMessageParamAttach
	{
		return $this->buildResultTextAttach($this->resultText);
	}
}
