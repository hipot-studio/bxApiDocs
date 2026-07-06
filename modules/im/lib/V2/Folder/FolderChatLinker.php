<?php

namespace Bitrix\Im\V2\Folder;

use Bitrix\Im\Model\FolderChatTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Im\V2\Error;
use Bitrix\Im\V2\Folder\Cache\FolderCache;
use Bitrix\Im\V2\Folder\Error\FolderError;
use Bitrix\Im\V2\Folder\Pin\PinService;
use Bitrix\Im\V2\Result;

/**
 * @internal
 */
class FolderChatLinker
{
	private const LIMIT_CHATS_PER_FOLDER = 50;

	public function __construct(
		private readonly PinService $pinService,
		private readonly FolderCache $folderCache,
		private readonly FolderProvider $folderProvider,
	) {}

	public function addChats(Folder $folder, array $chatIds): Result
	{
		$chatIds = Normalizer::toUniquePositiveIntegers($chatIds);
		if (empty($chatIds))
		{
			return new Result();
		}

		$result = new Result();
		if (!$folder instanceof PersonalFolder)
		{
			return $result->addError(new Error(FolderError::FOLDER_SYSTEM_MUTATION_FORBIDDEN));
		}

		$userId = (int)$folder->getUserId();
		$folderId = (int)$folder->getId();

		$eligibleChatIds = $this->filterEligibleChats($folder, $chatIds);
		if (empty($eligibleChatIds))
		{
			return $result->addError(new Error(FolderError::FOLDER_CHAT_NOT_ELIGIBLE));
		}

		$capacityError = $this->ensureCapacity($folder, count($eligibleChatIds));
		if ($capacityError !== null)
		{
			return $result->addError($capacityError);
		}

		$this->persistMembership($folderId, $eligibleChatIds);
		$this->pinService->syncShadowsOnFolderAttach($folder, $eligibleChatIds);
		$this->mergeChatIdsIntoFolder($folder, $eligibleChatIds);
		$this->folderCache->clearByUser($userId);

		return $result;
	}

	public function deleteChats(Folder $folder, array $chatIds): Result
	{
		$chatIds = Normalizer::toUniquePositiveIntegers($chatIds);
		if (empty($chatIds))
		{
			return new Result();
		}

		$result = new Result();
		if (!$folder instanceof PersonalFolder)
		{
			return $result->addError(new Error(FolderError::FOLDER_SYSTEM_MUTATION_FORBIDDEN));
		}

		$userId = (int)$folder->getUserId();
		$folderId = (int)$folder->getId();

		FolderChatTable::deleteBatch([
			'=FOLDER_ID' => $folderId,
			'=CHAT_ID' => $chatIds,
		]);

		$this->pinService->syncShadowsOnRemove($folderId, $chatIds, $userId);

		$folder->setChatIds(array_values(array_diff($folder->getChatIds(), $chatIds)));
		$this->folderCache->clearByUser($userId);

		return $result;
	}

	public function clearByFolder(int $folderId, ?int $userId = null): void
	{
		if ($folderId <= 0)
		{
			return;
		}

		FolderChatTable::deleteBatch(['=FOLDER_ID' => $folderId]);

		if ($userId !== null && $userId > 0)
		{
			$this->folderCache->clearByUser($userId);
		}
	}

	public function clearByUserAndChat(int $userId, int $chatId): void
	{
		if ($userId <= 0 || $chatId <= 0)
		{
			return;
		}

		$folderIds = $this->folderProvider->findFolderIdsByUser($userId);
		if (empty($folderIds))
		{
			return;
		}

		FolderChatTable::deleteBatch([
			'=FOLDER_ID' => $folderIds,
			'=CHAT_ID' => $chatId,
		]);

		$this->folderCache->clearByUser($userId);
	}

	public function clearByUser(int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		$folderIds = $this->folderProvider->findFolderIdsByUser($userId);
		if (empty($folderIds))
		{
			return;
		}

		FolderChatTable::deleteBatch(['=FOLDER_ID' => $folderIds]);

		$this->folderCache->clearByUser($userId);
	}

	private function filterEligibleChats(PersonalFolder $folder, array $chatIds): array
	{
		$chats = $this->getChats($chatIds, (int)$folder->getUserId());
		$eligible = [];
		foreach ($chats as $chat)
		{
			if ($folder->canAcceptChat($chat))
			{
				$eligible[] = (int)$chat->getChatId();
			}
		}

		return $eligible;
	}

	private function getChats(array $chatIds, int $userId): array
	{
		$chats = [];
		foreach ($chatIds as $chatId)
		{
			$chats[] = Chat::getInstance($chatId);
		}
		Chat::fillSelfRelations($chats, $userId);

		return $chats;
	}

	private function ensureCapacity(PersonalFolder $folder, int $additional): ?Error
	{
		if (count($folder->getChatIds()) + $additional <= self::LIMIT_CHATS_PER_FOLDER)
		{
			return null;
		}

		$this->lazyCleanupAtLimit($folder);

		if (count($folder->getChatIds()) + $additional <= self::LIMIT_CHATS_PER_FOLDER)
		{
			return null;
		}

		return new Error(FolderError::FOLDER_CHATS_LIMIT_EXCEEDED);
	}

	private function persistMembership(int $folderId, array $chatIds): void
	{
		$rows = [];
		foreach ($chatIds as $chatId)
		{
			$rows[] = [
				'FOLDER_ID' => $folderId,
				'CHAT_ID' => $chatId,
			];
		}
		FolderChatTable::multiplyInsertWithoutDuplicate(
			$rows,
			['UNIQUE_FIELDS' => ['FOLDER_ID', 'CHAT_ID']]
		);
	}

	private function mergeChatIdsIntoFolder(PersonalFolder $folder, array $newChatIds): void
	{
		$folder->setChatIds(array_values(array_unique(array_merge($folder->getChatIds(), $newChatIds))));
	}

	private function lazyCleanupAtLimit(PersonalFolder $folder): void
	{
		$existingChatIds = $folder->getChatIds();
		if (empty($existingChatIds))
		{
			return;
		}

		$userId = (int)$folder->getUserId();
		$folderId = (int)$folder->getId();

		$staleChatIds = [];
		foreach ($this->getChats($existingChatIds, $userId) as $chat)
		{
			if (!$chat->isExist() || !$chat->checkAccess($userId)->isSuccess())
			{
				$staleChatIds[] = (int)$chat->getChatId();
			}
		}

		if (empty($staleChatIds))
		{
			return;
		}

		FolderChatTable::deleteBatch([
			'=FOLDER_ID' => $folderId,
			'=CHAT_ID' => $staleChatIds,
		]);

		$this->pinService->syncShadowsOnRemove($folderId, $staleChatIds, $userId);

		// Reflect cleanup in folder's in-memory state so capacity re-check sees the freed slots.
		$folder->setChatIds(array_values(array_diff($existingChatIds, $staleChatIds)));
	}
}
