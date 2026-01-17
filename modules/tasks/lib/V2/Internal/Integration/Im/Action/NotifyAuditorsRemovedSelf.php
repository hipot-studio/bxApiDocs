<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity\User\Gender;

#[Recipients(creator: false, responsible: true, accomplices: true, auditors: false)]
class NotifyAuditorsRemovedSelf extends AbstractNotifyUsers
{
	public function getMessageCode(): string
	{
		return match ($this->triggeredBy?->getGender()) {
			Gender::Male   => 'TASKS_IM_TASK_AUDITORS_REMOVE_SELF_M',
			Gender::Female => 'TASKS_IM_TASK_AUDITORS_REMOVE_SELF_F',
			default        => 'TASKS_IM_TASK_AUDITORS_REMOVE_SELF_M',
		};
	}

	public function getMessageData(): array
	{
		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
		];
	}
}
