<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

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
			$hasDeadline && $hasResponsible => 'TASKS_IM_AI_UNRESOLVED_DEADLINE_AND_RESPONSIBLE',
			$hasDeadline => 'TASKS_IM_AI_UNRESOLVED_DEADLINE',
			$hasResponsible => 'TASKS_IM_AI_UNRESOLVED_RESPONSIBLE',
			default => null,
		};
	}

	public function getMessageData(): array
	{
		return [
			'#CREATOR#' => $this->formatUser($this->task->creator),
		];
	}
}
