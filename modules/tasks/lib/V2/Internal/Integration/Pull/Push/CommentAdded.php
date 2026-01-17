<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Pull\Push;

use Bitrix\Tasks\Integration\Pull\PushCommand;

class CommentAdded extends AbstractPayload
{
	public function __construct
	(
		public int $taskId,
		public int $ownerId,
		public int $messageId,
		public ?int $groupId,
		public array $participants,
		public bool $pullComment,
		public bool $isCompleteComment,
		public ?string $entityXmlId = null,
	)
	{
	}

	public function getCommand(): string
	{
		return PushCommand::COMMENT_ADDED;
	}

	/** @return array{taskId: int, entityXmlId: string, ownerId: int, messageId: int, groupId: int|null, participants: array, pullComment: bool, isCompleteComment: bool} */
	public function toArray(): array
	{
		return [
			'taskId' => $this->taskId,
			'entityXmlId' => $this->entityXmlId ?? 'TASK_' . $this->taskId,
			'ownerId' => $this->ownerId,
			'messageId' => $this->messageId,
			'groupId' => $this->groupId,
			'participants' => $this->participants,
			'pullComment' => $this->pullComment,
			'isCompleteComment' => $this->isCompleteComment,
		];
	}
}
