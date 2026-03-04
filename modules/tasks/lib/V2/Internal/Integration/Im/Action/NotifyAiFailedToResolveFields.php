<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\Integration\AI;
use Bitrix\Tasks\V2\Internal\Entity;

#[Recipients(creator: true, responsible: false, accomplices: false, auditors: false)]
class NotifyAiFailedToResolveFields extends AbstractNotify
{
	/**
	 * @param string[] $unresolvedFields
	 */
	public function __construct(
		private readonly Entity\Task $task,
		private readonly array $unresolvedFields,
		?Entity\User $triggeredBy = null,
	)
	{
		parent::__construct($triggeredBy);
	}

	public function getMessageCode(): string
	{
		$hasDeadline = in_array('deadline', $this->unresolvedFields, true);
		$hasResponsible = in_array('responsible', $this->unresolvedFields, true);

		return match (true)
		{
			$hasDeadline && $hasResponsible => 'TASKS_IM_AI_UNRESOLVED_DEADLINE_AND_RESPONSIBLE_MSGVER_1',
			$hasDeadline => 'TASKS_IM_AI_UNRESOLVED_DEADLINE_MSGVER_1',
			$hasResponsible => 'TASKS_IM_AI_UNRESOLVED_RESPONSIBLE_MSGVER_1',
			default => null,
		};
	}

	public function getMessageData(): array
	{
		return [
			'#CREATOR#' => $this->formatUser($this->task->creator),
			'#COPILOT_NAME#' => AI\Settings::getCopilotName(),
		];
	}
}
