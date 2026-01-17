<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity\User\Gender;
use Bitrix\Tasks\V2\Internal\Entity;

#[Recipients(
	creator: false,
	responsible: true,
	accomplices: true,
	auditors: false,

	mappers: [
		CounterRecipients\Mapper\DefaultMapper::class,
		CounterRecipients\Mapper\AddSpecificCounterRecipients::class,
		CounterRecipients\Mapper\ExcludeNotifyRecipients::class,
	],
)]
class NotifyAuditorsAssigned extends AbstractNotifyUsers implements ExcludeNotifyRecipientsInterface
{
	public function __construct(
		?Entity\User $triggeredBy = null,
		?Entity\UserCollection $users = null,
		protected readonly ?Entity\UserCollection $newAddMembers = null,
	)
	{
		parent::__construct($triggeredBy, $users);
	}

	public function getMessageCode(): string
	{
		return match ($this->triggeredBy?->getGender()) {
			Gender::Male   => 'TASKS_IM_TASK_AUDITORS_NEW_M',
			Gender::Female => 'TASKS_IM_TASK_AUDITORS_NEW_F',
			default        => 'TASKS_IM_TASK_AUDITORS_NEW_M',
		};
	}

	public function getMessageData(): array
	{
		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#NEW_AUDITORS#' => $this->formatUserList($this->users),
		];
	}

	public function getExcludedNotifyRecipients(): Entity\UserCollection
	{
		return $this->newAddMembers ?? new Entity\UserCollection();
	}
}
