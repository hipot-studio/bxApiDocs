<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im;

use Bitrix\Main\Config\Feature;
use Bitrix\Tasks\Feature\ChatAvatarFeature;
use Bitrix\Tasks\V2\Internal\Entity\Task;

class ChatAvatar
{
	public function isEnabled(): bool
	{
		return Feature::isEnabled(ChatAvatarFeature::class);
	}

	public function ensureFileId(ChatAvatarType $type): int
	{
		if (!$this->isEnabled())
		{
			return 0;
		}

		return $this->getPublisher()->ensureOriginal($type);
	}

	public function getTypeByTask(Task $task): ChatAvatarType
	{
		if ($this->isExpired($task))
		{
			return ChatAvatarType::Expired;
		}

		return match ($task->status)
		{
			Task\Status::Completed => ChatAvatarType::Completed,
			Task\Status::Deferred => ChatAvatarType::Deferred,
			default => ChatAvatarType::Default,
		};
	}

	protected function getPublisher(): ChatAvatarPublisher
	{
		return new ChatAvatarPublisher();
	}

	private function isExpired(Task $task): bool
	{
		if (!$task->deadlineTs || $this->isCompleteStatus($task))
		{
			return false;
		}

		return $task->deadlineTs <= time();
	}

	private function isCompleteStatus(Task $task): bool
	{
		return $task->status === Task\Status::Completed
			|| $task->status === Task\Status::SupposedlyCompleted
			|| $task->status === Task\Status::Deferred;
	}
}
