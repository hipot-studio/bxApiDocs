<?php

namespace Bitrix\Im\V2\Folder;

use Bitrix\Im\Model\FolderChatTable;
use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Im\V2\Folder\Pull\FolderChatAdd;
use Bitrix\Im\V2\Folder\Pull\FolderChatDelete;
use Bitrix\Im\V2\Result;

class FolderChatService
{
	public function __construct(
		private readonly FolderChatLinker $linker,
		private readonly FolderProvider $folderProvider,
	)
	{
	}

	public function addChats(Folder $folder, array $chatIds): Result
	{
		$result = $this->linker->addChats($folder, $chatIds);
		if ($result->isSuccess() && !empty($chatIds))
		{
			$userId = (int)$folder->getUserId();
			(new FolderChatAdd($folder, $userId))->send();
		}

		return $result;
	}

	public function deleteChats(Folder $folder, array $chatIds): Result
	{
		$result = $this->linker->deleteChats($folder, $chatIds);
		if ($result->isSuccess() && !empty($chatIds))
		{
			$userId = (int)$folder->getUserId();
			(new FolderChatDelete($folder, $userId))->send();
		}

		return $result;
	}

	/**
	 * Sets the full set of personal-folder memberships for a chat.
	 * Diffs current vs new, UPSERT/DELETE accordingly.
	 *
	 * Folders that are not owned by $userId or are not personal are silently ignored.
	 *
	 * @param int[] $folderIds
	 */
	public function setChatFolders(int $userId, int $chatId, array $folderIds): Result
	{
		$result = new Result();
		$folderIds = Normalizer::toUniquePositiveIntegers($folderIds);

		// 1. Current memberships of this chat across all $userId's personal folders.
		$rows = FolderChatTable::query()
			->setSelect(['FOLDER_ID'])
			->where('CHAT_ID', $chatId)
			->where('FOLDER.USER_ID', $userId)
			->where('FOLDER.TYPE', PersonalFolder::TYPE)
			->fetchAll()
		;

		$currentFolderIds = Normalizer::toUniquePositiveIntegers(array_column($rows, 'FOLDER_ID'));

		// 2. Single batch-resolve of all relevant folders (target + current).
		$foldersById = $this->folderProvider->getByIds(array_merge($folderIds, $currentFolderIds));

		// 3. Filter target: only PersonalFolder owned by $userId.
		/** @var array<int, PersonalFolder> $validTargetFoldersById */
		$validTargetFoldersById = [];
		foreach ($folderIds as $candidateId)
		{
			$folder = $foldersById[$candidateId] ?? null;
			if (!$folder instanceof PersonalFolder)
			{
				continue;
			}
			if ((int)($folder->getUserId() ?? 0) !== $userId)
			{
				continue;
			}
			$validTargetFoldersById[$candidateId] = $folder;
		}

		$toAdd = array_values(array_diff(array_keys($validTargetFoldersById), $currentFolderIds));
		$toRemove = array_values(array_diff($currentFolderIds, array_keys($validTargetFoldersById)));

		foreach ($toAdd as $folderId)
		{
			$res = $this->addChats($validTargetFoldersById[$folderId], [$chatId]);
			if (!$res->isSuccess())
			{
				$result->addErrors($res->getErrors());
			}
		}

		foreach ($toRemove as $folderId)
		{
			$folder = $foldersById[$folderId] ?? null;
			if (!$folder instanceof PersonalFolder)
			{
				continue;
			}
			$res = $this->deleteChats($folder, [$chatId]);
			if (!$res->isSuccess())
			{
				$result->addErrors($res->getErrors());
			}
		}

		return $result;
	}
}
