<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;

#[Recipients(creator: true)]
class NotifyAccessRequested extends AbstractNotify implements ShouldSend
{
	public function getMessageCode(): string
	{
		$gender = $this->triggeredBy?->getGender();

		return match ($gender) {
			Entity\User\Gender::Female => 'TASKS_IM_TASK_ACCESS_REQUESTED_F',
			default => 'TASKS_IM_TASK_ACCESS_REQUESTED_M',
		};
	}

	public function getMessageData(): array
	{
		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
		];
	}
}
