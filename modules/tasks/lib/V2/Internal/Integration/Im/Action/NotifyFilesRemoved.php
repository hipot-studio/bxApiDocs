<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;

#[Recipients(creator: false, responsible: false, accomplices: false, auditors: false)]
class NotifyFilesRemoved extends AbstractNotify implements ShouldSend
{
	public function __construct(
		private readonly Entity\Task $task,
		protected readonly ?Entity\User $triggeredBy = null,
		protected readonly ?array $fileIds = null,
	) {
	}

	public function getMessageCode(): string
	{
		$filesCount = $this->fileIds ? count($this->fileIds) : 1;
		$gender = $this->triggeredBy?->getGender();

		if ($filesCount === 1)
		{
			return match ($gender) {
				Entity\User\Gender::Female => 'TASKS_IM_TASK_FILE_REMOVED_F',
				default => 'TASKS_IM_TASK_FILE_REMOVED_M',
			};
		}

		return match ($gender) {
			Entity\User\Gender::Female => 'TASKS_IM_TASK_FILES_REMOVED_F',
			default => 'TASKS_IM_TASK_FILES_REMOVED_M',
		};
	}

	public function getMessageData(): array
	{
		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
		];
	}
}
