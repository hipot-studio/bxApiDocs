<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;
use Bitrix\Tasks\V2\Internal\Service\Link\LinkService;

#[Recipients(creator: false, responsible: true, accomplices: true, auditors: true, mappers: [
	CounterRecipients\Mapper\DefaultMapper::class,
	CounterRecipients\Mapper\ExcludeNotifyRecipients::class,
])]
class NotifyTaskCreated extends AbstractNotify implements ExcludeNotifyRecipientsInterface
{
	private readonly LinkService $linkService;

	public function __construct(
		private readonly Entity\Task $task,
		MessageSenderInterface $sender,
		protected readonly ?Entity\User $triggeredBy = null,
	)
	{
		$this->linkService = ServiceLocator::getInstance()->get(LinkService::class);

		$sender->sendMessage(task: $task, notification: $this);
	}

	public function getMessageCode(): string
	{
		if ($this->task->scenarios?->contains(Entity\Task\Scenario::Voice))
		{
			return match ($this->triggeredBy?->getGender()) {
				Entity\User\Gender::Male   => 'TASKS_IM_AUDIO_TASK_CREATED_M',
				Entity\User\Gender::Female => 'TASKS_IM_AUDIO_TASK_CREATED_F',
				default                    => 'TASKS_IM_AUDIO_TASK_CREATED_M',
			};
		}

		if ($this->task->scenarios?->contains(Entity\Task\Scenario::Video))
		{
			return match ($this->triggeredBy?->getGender()) {
				Entity\User\Gender::Male   => 'TASKS_IM_VIDEO_TASK_CREATED_M',
				Entity\User\Gender::Female => 'TASKS_IM_VIDEO_TASK_CREATED_F',
				default                    => 'TASKS_IM_VIDEO_TASK_CREATED_M',
			};
		}

		return match ($this->triggeredBy?->getGender()) {
			Entity\User\Gender::Male   => 'TASKS_IM_TASK_CREATED_M_MSGVER_1',
			Entity\User\Gender::Female => 'TASKS_IM_TASK_CREATED_F_MSGVER_1',
			default                    => 'TASKS_IM_TASK_CREATED_M_MSGVER_1',
		};
	}

	public function getMessageData(): array
	{
		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#TASK_URL#' => $this->linkService->get($this->task, (int)$this->triggeredBy?->id),
		];
	}

	public function getDisableNotify(): bool
	{
		return true;
	}

	public function shouldDisableGenerateUrlPreview(): bool
	{
		return false;
	}

	public function getExcludedNotifyRecipients(): UserCollection
	{
		return $this->task->getMembers();
	}
}
