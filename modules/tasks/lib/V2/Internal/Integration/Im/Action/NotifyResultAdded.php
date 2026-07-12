<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

#[Recipients(creator: true, responsible: true, accomplices: true, auditors: false)]
class NotifyResultAdded extends AbstractNotifyWithFiles
{
	public function __construct(
		private readonly Entity\Task $task,
		MessageSenderInterface $sender,
		protected readonly ?Entity\User $triggeredBy = null,
		private readonly ChatActionLinkService $chatActionLinkService,
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
		$attach = new \CIMMessageParamAttach();

		$resultText = $this->prepareResultText();
		if (empty($resultText))
		{
			return null;
		}

		$attach->AddMessage('[b]' . Loc::getMessage('TASKS_IM_NOTIFY_ATTACH_RESULT_TEXT') . '[/b][br]');
		$attach->AddMessage($resultText);

		return $attach;
	}

	public function getTaskAttachIds(): array
	{
		return $this->fileIds;
	}

	private function prepareResultText(): string
	{
		if ($this->resultText === '')
		{
			return '';
		}

		$resultText = htmlspecialchars_decode(htmlspecialcharsback($this->resultText), ENT_QUOTES);
		$resultText = trim(\CTextParser::clearAllTags($resultText));
		$resultText = str_replace(
			["&#91;", "&#93;"],
			["[", "]"],
			$resultText,
		);

		return preg_replace('/\n{3,}/', "\n\n", $resultText);
	}
}
