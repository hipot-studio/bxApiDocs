<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder;

use Bitrix\Im\V2\Folder\Pin\PinService;

class FolderCascadeHandler
{
	public function __construct(
		private readonly FolderService $folderService,
		private readonly FolderChatLinker $folderChatLinker,
		private readonly PinService $pinService,
	)
	{
	}

	public function onUserLeave(int $chatId, int $userId): void
	{
		if ($chatId <= 0 || $userId <= 0)
		{
			return;
		}

		$this->folderChatLinker->clearByUserAndChat($userId, $chatId);
		$this->pinService->clearByUserAndChat($userId, $chatId);
	}

	public function deleteByUser(int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		$this->pinService->clearByUser($userId);
		$this->folderChatLinker->clearByUser($userId);
		$this->folderService->deleteAllByUser($userId);
	}
}
