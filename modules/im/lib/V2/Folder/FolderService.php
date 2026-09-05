<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder;

use Bitrix\Im\Model\FolderTable;
use Bitrix\Im\V2\Chat\ChatIds;
use Bitrix\Im\V2\Error;
use Bitrix\Im\V2\Folder\Cache\FolderCache;
use Bitrix\Im\V2\Folder\Create\CreateResult;
use Bitrix\Im\V2\Folder\Create\FolderFields;
use Bitrix\Im\V2\Folder\Delete\DeleteResult;
use Bitrix\Im\V2\Folder\Error\FolderError;
use Bitrix\Im\V2\Folder\Pin\PinService;
use Bitrix\Im\V2\Folder\Pull\FolderCreate;
use Bitrix\Im\V2\Folder\Pull\FolderDelete;
use Bitrix\Im\V2\Folder\Pull\FolderUpdate;
use Bitrix\Im\V2\Folder\Update\UpdateFields;
use Bitrix\Im\V2\Folder\Update\UpdateResult;
use Bitrix\Main\Validation\ValidationService;

class FolderService
{
	public function __construct(
		private readonly FolderChatLinker $folderChatLinker,
		private readonly PinService $pinService,
		private readonly FolderCache $folderCache,
		private readonly FolderProvider $folderProvider,
		private readonly ValidationService $validationService,
	)
	{
	}

	public function create(FolderFields $fields, int $userId): CreateResult
	{
		$result = new CreateResult();

		if ($userId <= 0)
		{
			return $result->addError(new Error(FolderError::FOLDER_ACCESS_DENIED));
		}

		$validation = $this->validationService->validate($fields);
		if (!$validation->isSuccess())
		{
			return $result->addErrors($validation->getErrors());
		}

		// Per-user folder limit (personal only — system folders are bootstrapped lazily).
		$folders = $this->folderProvider->getByUser($userId);
		if ($folders->countPersonal() >= FolderLimits::MAX_FOLDERS_PER_USER)
		{
			return $result->addError(new Error(FolderError::FOLDER_LIMIT_EXCEEDED));
		}

		$createdFolder = new PersonalFolder();
		$createdFolder
			->setUserId($userId)
			->setParentChatId(0)
			->setType(PersonalFolder::TYPE)
			->setCode(null)
			->setTitle($fields->title)
			->setSort($folders->getMaxSort() + 1)
		;

		$saveResult = $createdFolder->save();
		if (!$saveResult->isSuccess())
		{
			return $result->addErrors($saveResult->getErrors());
		}

		$this->folderCache->clearByUser($userId);

		if (!empty($fields->chatIds))
		{
			$addChatsResult = $this->folderChatLinker->addChats($createdFolder, $fields->chatIds);
			if (!$addChatsResult->isSuccess())
			{
				$result->addErrors($addChatsResult->getErrors());
			}
		}

		(new FolderCreate($createdFolder, $userId))->send();

		return $result->setFolder($createdFolder);
	}

	public function update(Folder $folder, UpdateFields $fields): UpdateResult
	{
		$result = new UpdateResult();

		if (!$folder instanceof PersonalFolder)
		{
			return $result->addError(new Error(FolderError::FOLDER_SYSTEM_MUTATION_FORBIDDEN));
		}

		$validation = $this->validationService->validate($fields);
		if (!$validation->isSuccess())
		{
			return $result->addErrors($validation->getErrors());
		}

		$userId = (int)$folder->getUserId();

		// Partial update: title is optional (uninitialized when omitted), composition is optional (null).
		$titleProvided = isset($fields->title);
		if (!$titleProvided && $fields->chatIds === null)
		{
			// Nothing to change — no-op, no folderUpdate noise.
			return $result->setFolder($folder);
		}

		if ($fields->chatIds !== null)
		{
			$setResult = $this->applyChatComposition($folder, $fields->chatIds);
			if (!$setResult->isSuccess())
			{
				return $result->addErrors($setResult->getErrors());
			}
		}

		if ($titleProvided)
		{
			$folder->setTitle($fields->title);

			$saveResult = $folder->save();
			if (!$saveResult->isSuccess())
			{
				return $result->addErrors($saveResult->getErrors());
			}
		}

		$this->folderCache->clearByUser($userId);

		(new FolderUpdate($folder, $userId))->send();

		return $result->setFolder($folder);
	}

	/**
	 * Precondition: called only for a PersonalFolder (system folders are rejected earlier in
	 * update()). An empty set clears the whole composition.
	 */
	private function applyChatComposition(PersonalFolder $folder, ChatIds $composition): UpdateResult
	{
		$result = new UpdateResult();

		$target = $composition->values;

		// Empty set — clear the whole composition.
		if ($target === [])
		{
			$deleteResult = $this->folderChatLinker->deleteChats($folder, $folder->getChatIds());

			return $deleteResult->isSuccess()
				? $result
				: $result->addErrors($deleteResult->getErrors());
		}

		// Reject an over-limit target up front, before any membership mutation (so a too-large
		// set never leaves a partial state). lazyCleanup of stale shadows still runs inside addChats.
		if (count($target) > FolderLimits::MAX_CHATS_PER_FOLDER)
		{
			return $result->addError(new Error(FolderError::FOLDER_CHATS_LIMIT_EXCEEDED));
		}

		$current = $folder->getChatIds();
		$toAdd = array_values(array_diff($target, $current));
		$toRemove = array_values(array_diff($current, $target));

		// Remove before add so a set that swaps chats on a folder at the limit is not rejected:
		// the incremental capacity check would otherwise count current + toAdd before the removal.
		if (!empty($toRemove))
		{
			$removeResult = $this->folderChatLinker->deleteChats($folder, $toRemove);
			if (!$removeResult->isSuccess())
			{
				return $result->addErrors($removeResult->getErrors());
			}
		}

		if (!empty($toAdd))
		{
			$addResult = $this->folderChatLinker->addChats($folder, $toAdd);
			if (!$addResult->isSuccess())
			{
				return $result->addErrors($addResult->getErrors());
			}
		}

		return $result;
	}

	public function delete(Folder $folder): DeleteResult
	{
		$result = new DeleteResult();

		if (!$folder instanceof PersonalFolder)
		{
			return $result->addError(new Error(FolderError::FOLDER_SYSTEM_MUTATION_FORBIDDEN));
		}

		$folderId = (int)$folder->getId();
		$userId = (int)$folder->getUserId();

		$this->pinService->clearByFolder($folderId, $userId);
		$this->folderChatLinker->clearByFolder($folderId, $userId);

		$deleteResult = $folder->delete();
		if (!$deleteResult->isSuccess())
		{
			return $result->addErrors($deleteResult->getErrors());
		}

		(new FolderDelete($folderId, $userId))->send();

		return $result->setFolderId($folderId);
	}

	public function deleteAllByUser(int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		FolderTable::deleteBatch(['=USER_ID' => $userId]);

		$this->folderCache->clearByUser($userId);
	}
}
