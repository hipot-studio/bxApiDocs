<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

#[Recipients(creator: true, responsible: false, accomplices: false, auditors: false)]
class OnboardingInvitedResponsibleNotAcceptOneDay extends AbstractNotify
{
	public function __construct(
		private readonly Entity\Task $task,
		MessageSenderInterface $sender,
	)
	{
		parent::__construct();
		$sender->sendMessage(task: $task, notification: $this);
	}

	public function getMessageCode(): string
	{
		return 'TASKS_IM_ONBOARDING_INVITED_RESPONSIBLE_NOT_ACCEPT_ONE_DAY';
	}

	public function getMessageData(): array
	{
		return [
			'#CREATED_BY#' => $this->formatUser($this->task->creator),
		];
	}
}
