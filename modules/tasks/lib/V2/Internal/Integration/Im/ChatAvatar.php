<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\IO\File;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\Json;
use Bitrix\Tasks\Internals\Counter\Deadline;
use Bitrix\Tasks\V2\Internal\Entity\Task;

class ChatAvatar
{
	private const OPTION_MODULE = 'tasks';
	private const OPTION_NAME = 'chat_avatar_file_ids';
	private const FEATURE_OPTION_NAME = 'chat_avatar_feature';

	public function isEnabled(): bool
	{
		return Option::get(self::OPTION_MODULE, self::FEATURE_OPTION_NAME, 'N') === 'Y';
	}

	public function ensureFileId(ChatAvatarType $type): int
	{
		if (!$this->isEnabled())
		{
			return 0;
		}

		$option = $this->getOption();

		$fileId = (int)($option[$type->value] ?? 0);

		if (!empty($fileId))
		{
			$file = \CFile::GetByID($fileId)->Fetch();
			if (!empty($file))
			{
				return $fileId;
			}
		}

		return $this->saveFile($type);
	}

	public function getTypeByTask(Task $task): ChatAvatarType
	{
		if ($this->isExpired($task))
		{
			return ChatAvatarType::Expired;
		}

		if ($this->isExpiredSoon($task))
		{
			return ChatAvatarType::ExpiredSoon;
		}

		return match ($task->status)
		{
			Task\Status::Completed => ChatAvatarType::Completed,
			Task\Status::Deferred => ChatAvatarType::Deferred,
			default => ChatAvatarType::Default,
		};
	}

	private function getPath(ChatAvatarType $type): string
	{
		return  Application::getDocumentRoot() . '/bitrix/images/tasks/chat_avatar/' . $type->value . '.png';
	}

	private function getOption(): array
	{
		$option = Option::get(self::OPTION_MODULE, self::OPTION_NAME);

		try
		{
			return Json::decode($option);
		}
		catch (SystemException)
		{
			return [];
		}
	}

	private function saveOption(array $option): void
	{
		Option::set(self::OPTION_MODULE, self::OPTION_NAME, Json::encode($option));
	}

	private function saveFile(ChatAvatarType $type): int
	{
		$filePath = $this->getPath($type);
		if (!File::isFileExists($filePath))
		{
			return 0;
		}

		$file = \CFile::MakeFileArray($filePath);
		if (empty($file))
		{
			return 0;
		}

		$file['MODULE_ID'] = 'tasks';

		$fileId = \CFile::SaveFile($file, 'tasks');
		if ($fileId > 0)
		{
			$option = $this->getOption();
			$option[$type->value] = (int)$fileId;

			$this->saveOption($option);
		}

		return $fileId;
	}

	private function isExpired(Task $task): bool
	{
		if (!$task->deadlineTs || $this->isCompleteStatus($task))
		{
			return false;
		}

		return $task->deadlineTs <= time();
	}

	private function isExpiredSoon(Task $task): bool
	{
		if (!$task->deadlineTs || $this->isCompleteStatus($task))
		{
			return false;
		}

		return $task->deadlineTs <= time() + Deadline::getDeadlineTimeLimit();
	}

	private function isCompleteStatus(Task $task): bool
	{
		return $task->status === Task\Status::Completed
			|| $task->status === Task\Status::SupposedlyCompleted
			|| $task->status === Task\Status::Deferred
		;
	}
}
