<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity\User\Gender;

#[Recipients(creator: false, responsible: true, accomplices: true, auditors: false, mappers: [
	CounterRecipients\Mapper\DefaultMapper::class,
	CounterRecipients\Mapper\AddSpecificCounterRecipients::class,
],)]
class NotifyAccomplicesRemoved extends AbstractNotifyUsers
{
	public function toString(): string
	{
		$count = (int)$this->users?->count();

		return $this->toPluralString($count === 1 ? 1 : 2);
	}

	public function getMessageCode(): string
	{
		return match ($this->triggeredBy?->getGender()) {
			Gender::Male   => 'TASKS_IM_TASK_ACCOMPLICES_REMOVE_M_MSGVER_1',
			Gender::Female => 'TASKS_IM_TASK_ACCOMPLICES_REMOVE_F_MSGVER_1',
			default        => 'TASKS_IM_TASK_ACCOMPLICES_REMOVE_M_MSGVER_1',
		};
	}

	public function getMessageData(): array
	{
		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#OLD_ACCOMPLICES#' => $this->formatUserList($this->users),
		];
	}
}
