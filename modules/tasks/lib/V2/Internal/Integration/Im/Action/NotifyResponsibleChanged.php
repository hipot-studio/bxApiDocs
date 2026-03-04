<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

#[Recipients(creator: true, responsible: false, accomplices: true, auditors: false,
	mappers: [
		CounterRecipients\Mapper\DefaultMapper::class,
		CounterRecipients\Mapper\AddSpecificRecipients::class,
		CounterRecipients\Mapper\ExcludeNotifyRecipients::class,
	],
)]
class NotifyResponsibleChanged extends AbstractNotify implements
	SpecificCounterRecipientsInterface,
	ExcludeNotifyRecipientsInterface
{
	public function __construct(
		private readonly Entity\Task $task,
		MessageSenderInterface $sender,
		protected readonly ?Entity\User $triggeredBy = null,
		private readonly ?Entity\User $oldResponsible = null,
		private readonly ?Entity\User $newResponsible = null,
		private readonly bool $isNewMember = false,
	)
	{
		$sender->sendMessage(task: $task, notification: $this);
	}

	public function getMessageCode(): string
	{
		return match ($this->triggeredBy?->getGender()) {
			Entity\User\Gender::Male   => 'TASKS_IM_TASK_RESPONSIBLE_CHANGED_M',
			Entity\User\Gender::Female => 'TASKS_IM_TASK_RESPONSIBLE_CHANGED_F',
			default                    => 'TASKS_IM_TASK_RESPONSIBLE_CHANGED_M',
		};
	}

	public function getMessageData(): array
	{
		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#OLD_RESPONSIBLE#' => $this->formatUser($this->oldResponsible),
			'#NEW_RESPONSIBLE#' => $this->formatUser($this->newResponsible),
		];
	}

	public function getSpecificCounterRecipients(): Entity\UserCollection
	{
		$collection = new Entity\UserCollection();

		if ($this->newResponsible)
		{
			$collection->add($this->newResponsible);
		}

		return $collection;
	}

	public function getExcludedNotifyRecipients(): UserCollection
	{
		$collection = new Entity\UserCollection();

		if ($this->isNewMember && $this->newResponsible)
		{
			$collection->add($this->newResponsible);
		}

		return $collection;
	}
}
