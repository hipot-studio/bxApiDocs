<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\Internals\Task\MemberTable;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;

#[Recipients(creator: false, responsible: true, accomplices: true, auditors: false)]
class NotifyTaskStatusPinged extends AbstractNotify implements ShouldSend
{
	private readonly string $role;

	/** @var array{O: string, R: string, A: string, U: string, default: string} */
	private array $messageCodesDefaults = [
		MemberTable::MEMBER_TYPE_ORIGINATOR => 'TASKS_IM_TASK_STATUS_PINGED_CREATOR_M',
		MemberTable::MEMBER_TYPE_RESPONSIBLE => 'TASKS_IM_TASK_STATUS_PINGED_RESPONSIBLE_M',
		MemberTable::MEMBER_TYPE_ACCOMPLICE => 'TASKS_IM_TASK_STATUS_PINGED_ACCOMPLICE_M',
		MemberTable::MEMBER_TYPE_AUDITOR => 'TASKS_IM_TASK_STATUS_PINGED_AUDITOR_M',
		'default' => 'TASKS_IM_TASK_STATUS_PINGED_M',
	];

	private array $messageCodesGenders = [
		MemberTable::MEMBER_TYPE_ORIGINATOR => [
			'F' => 'TASKS_IM_TASK_STATUS_PINGED_CREATOR_F',
			'M' => 'TASKS_IM_TASK_STATUS_PINGED_CREATOR_M',
			'N' => 'TASKS_IM_TASK_STATUS_PINGED_CREATOR_M',
		],
		MemberTable::MEMBER_TYPE_RESPONSIBLE => [
			'F' => 'TASKS_IM_TASK_STATUS_PINGED_RESPONSIBLE_F',
			'M' => 'TASKS_IM_TASK_STATUS_PINGED_RESPONSIBLE_M',
			'N' => 'TASKS_IM_TASK_STATUS_PINGED_RESPONSIBLE_M',
		],
		MemberTable::MEMBER_TYPE_ACCOMPLICE => [
			'F' => 'TASKS_IM_TASK_STATUS_PINGED_ACCOMPLICE_F',
			'M' => 'TASKS_IM_TASK_STATUS_PINGED_ACCOMPLICE_M',
			'N' => 'TASKS_IM_TASK_STATUS_PINGED_ACCOMPLICE_M',
		],
		MemberTable::MEMBER_TYPE_AUDITOR => [
			'F' => 'TASKS_IM_TASK_STATUS_PINGED_AUDITOR_F',
			'M' => 'TASKS_IM_TASK_STATUS_PINGED_AUDITOR_M',
			'N' => 'TASKS_IM_TASK_STATUS_PINGED_AUDITOR_M',
		],
		'default' => [
			'F' => 'TASKS_IM_TASK_STATUS_PINGED_F',
			'M' => 'TASKS_IM_TASK_STATUS_PINGED_M',
			'N' => 'TASKS_IM_TASK_STATUS_PINGED_N',
		],
	];
	
	public function __construct(
		private readonly Entity\Task $task,
		protected readonly ?Entity\User $triggeredBy,
	)
	{
		if ($this->triggeredBy?->isEquals($this->task->creator))
		{
			$this->role = MemberTable::MEMBER_TYPE_ORIGINATOR;
		}
		elseif ($this->triggeredBy?->isEquals($this->task->responsible))
		{
			$this->role = MemberTable::MEMBER_TYPE_RESPONSIBLE;
		}
		elseif ($this->triggeredBy !== null && $this->task->accomplices->find(fn(Entity\User $user) => $user->isEquals($this->triggeredBy)))
		{
			$this->role = MemberTable::MEMBER_TYPE_ACCOMPLICE;
		}
		elseif ($this->triggeredBy !== null && $this->task->auditors->find(fn(Entity\User $user) => $user->isEquals($this->triggeredBy)))
		{
			$this->role = MemberTable::MEMBER_TYPE_AUDITOR;
		}
		else
		{
			$this->role = 'default';
		}
	}

	public function getMessageCode(): string
	{
		if (null === $this->triggeredBy)
		{
			return $this->messageCodesDefaults[$this->role];
		}

		return $this->messageCodesGenders[$this->role][$this->triggeredBy?->getGender()->value];
	}

	public function getMessageData(): array
	{
		/** @var Entity\User[] $audience */
		$entities = [];
		$entities[$this->task->responsible->getId()] = $this->task->responsible;

		foreach ($this->task->accomplices->getEntities() as $user)
		{
			$entities[$user->getId()] = $user;
		}

		$collection = new UserCollection(...$entities);

		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#MEMBERS#' => $this->formatUserList($collection),
		];
	}
}
