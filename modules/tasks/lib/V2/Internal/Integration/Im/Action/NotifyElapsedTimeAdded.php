<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;

class NotifyElapsedTimeAdded extends AbstractNotify implements ShouldSend
{
	public function __construct(
		private readonly Entity\Task $task,
		protected readonly ?Entity\User $triggeredBy = null,
		protected readonly ?Entity\Task\ElapsedTime $elapsedTime,
		private readonly ChatActionLinkService $chatActionLinkService,
	)
	{
	}

	public function getMessageCode(): string
	{
		if (!empty($this->elapsedTime->text))
		{
			return match ($this->triggeredBy?->getGender())
			{
				Entity\User\Gender::Female => 'TASKS_IM_TASK_ELAPSED_TIME_ADDED_WITH_TEXT_F_MSGVER_1',
				default => 'TASKS_IM_TASK_ELAPSED_TIME_ADDED_WITH_TEXT_M_MSGVER_1',
			};
		}

		return match ($this->triggeredBy?->getGender())
		{
			Entity\User\Gender::Female => 'TASKS_IM_TASK_ELAPSED_TIME_ADDED_F_MSGVER_1',
			default => 'TASKS_IM_TASK_ELAPSED_TIME_ADDED_M_MSGVER_1',
		};
	}

	public function getMessageData(): array
	{
		$actionLink = $this->chatActionLinkService->get(
			task: $this->task,
			userId: (int)$this->triggeredBy?->getId(),
			action: ChatAction::OpenTimeTracking,
			entityId: (int)$this->elapsedTime->id,
		);

		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#ACTION_LINK#' => $actionLink,
			'#TIME#' => $this->formatElapsedTime($this->elapsedTime->seconds),
			'#TEXT#' => $this->elapsedTime->text,
		];
	}
}
