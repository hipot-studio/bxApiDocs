<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;

#[Recipients(creator: false, responsible: true, accomplices: true, auditors: false)]
class NotifyResultRequested extends AbstractNotify implements ShouldSend
{
	public function __construct(
		private readonly Entity\Task $task,
		protected readonly ?Entity\User $triggeredBy = null,
	) {
	}

	public function getMessageCode(): string
	{
		return 'TASKS_IM_RESULT_REQUESTED';
	}
}
