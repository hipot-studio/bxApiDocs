<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;

#[Recipients(creator: false, responsible: true, accomplices: true, auditors: false)]
class NotifyGroupAdded extends AbstractNotify
{
	public function __construct(
		protected readonly ?Entity\User $triggeredBy = null,
		private readonly Entity\Group $group,
	)
	{
	}

	public function getMessageCode(): string
	{
		$secretCode = $this->group->isVisible ? '' : 'SECRET_';

		return match($this->triggeredBy?->getGender()) {
			Entity\User\Gender::Male => "TASKS_IM_TASK_GROUP_ADDED_{$secretCode}M",
			Entity\User\Gender::Female => "TASKS_IM_TASK_GROUP_ADDED_{$secretCode}F",
			default => "TASKS_IM_TASK_GROUP_ADDED_{$secretCode}M",
		};
	}

	public function getMessageData(): array
	{
		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#NEW_GROUP#' => $this->group->name,
		];
	}
}
