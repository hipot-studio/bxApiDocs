<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;

#[Recipients(creator: true)]
class NotifyAccessRequested extends AbstractNotify implements ShouldSend
{
	public function __construct(
		private readonly Entity\Task $task,
		private readonly ChatActionLinkService $chatActionLinkService,
		?Entity\User $triggeredBy = null,
	)
	{
		parent::__construct($triggeredBy);
	}

	public function getMessageCode(): string
	{
		$gender = $this->triggeredBy?->getGender();

		return match ($gender) {
			Entity\User\Gender::Female => 'TASKS_IM_TASK_ACCESS_REQUESTED_F_MSGVER_2',
			default => 'TASKS_IM_TASK_ACCESS_REQUESTED_M_MSGVER_2',
		};
	}

	public function getMessageData(): array
	{
		$actionLink = $this->chatActionLinkService->get(
			task: $this->task,
			userId: (int)$this->triggeredBy?->id,
			action: ChatAction::AddObserver,
			entityId: (int)$this->triggeredBy?->id,
		);

		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#ADD_OBSERVER_URL#' => $actionLink,
		];
	}

	public function isClickable(): bool
	{
		return true;
	}
}
