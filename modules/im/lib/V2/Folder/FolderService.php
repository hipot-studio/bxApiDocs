<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder;

use Bitrix\Im\Model\FolderSourceTable;
use Bitrix\Im\Model\FolderTable;
use Bitrix\Im\V2\Analytics\FolderAnalytics;
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

	/**
	 * A thin wrapper around the write path: creation has six points of return, and the label needs
	 * every one of them, so the single point of sending lives here and not next to each of them.
	 */
	public function create(FolderFields $fields, int $userId): CreateResult
	{
		$result = $this->createInternal($fields, $userId);

		// A folder stored together with an error of its composition is still a created folder, so the
		// outcome of the label is read from the folder and not from isSuccess().
		(new FolderAnalytics($userId))->addSubmitCreateFolder(
			$fields->chatIds,
			$result->getFolder() === null ? $result->getError()?->getCode() : null,
		);

		return $result;
	}

	private function createInternal(FolderFields $fields, int $userId): CreateResult
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

		// Initial composition is checked before the folder is stored, otherwise a rejected chat
		// would leave an empty folder behind together with the error.
		$ineligibleChatIds = $this->folderChatLinker->findIneligibleChats($createdFolder, $fields->chatIds);
		if (!empty($ineligibleChatIds))
		{
			return $result->addError(
				new Error(FolderError::FOLDER_CHAT_NOT_ELIGIBLE, ['chatIds' => $ineligibleChatIds])
			);
		}

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

		// A stored chat the folder can no longer show is taken out by the server, and comes back inside
		// the incoming set because the client edits the folder from the composition it was given.
		// Subtracting it keeps it a stored chat that leaves and not a chat this request adds.
		$staleChatIds = $this->folderChatLinker->findStaleMembership($folder);
		$target = array_values(array_diff($composition->values, $staleChatIds));

		// Empty set — clear the whole composition.
		if ($target === [])
		{
			$deleteResult = $this->folderChatLinker->deleteChats($folder, $folder->getChatIds());

			return $deleteResult->isSuccess()
				? $result
				: $result->addErrors($deleteResult->getErrors());
		}

		// Reject an over-limit target up front, before the requested composition is touched (so a
		// too-large set never leaves a partial state). The stale chats are already out of the count:
		// none of them asks the folder for room.
		if (count($target) > FolderLimits::MAX_CHATS_PER_FOLDER)
		{
			return $result->addError(new Error(FolderError::FOLDER_CHATS_LIMIT_EXCEEDED));
		}

		$current = $folder->getChatIds();
		$toAdd = array_values(array_diff($target, $current));
		// Holds the stale chats as well: they are stored and out of the target, so the ordinary removal
		// below takes their rows out - after the verdict on the added chats and never before it.
		$toRemove = array_values(array_diff($current, $target));

		// Only the chats this request adds are checked, and before the first mutation, so a rejected
		// chat leaves the composition exactly as it was. A chat already stored in the folder either
		// stays or leaves: it is not this request that put it there, and refusing it would leave the
		// folder unusable.
		$ineligibleChatIds = $this->folderChatLinker->findIneligibleChats($folder, $toAdd);
		if (!empty($ineligibleChatIds))
		{
			return $result->addError(
				new Error(FolderError::FOLDER_CHAT_NOT_ELIGIBLE, ['chatIds' => $ineligibleChatIds])
			);
		}

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

			// The same fact FolderChatService::addChats() reports on the other entry: chats of one call
			// join one folder. The linker is asked directly here (the pull of the whole update is sent
			// by the caller), so the label is sent here as well and not through that service.
			(new FolderAnalytics((int)$folder->getUserId()))->addAddChat($toAdd);
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
		// Read before the first mutation below: the memberships are gone by the time the delete succeeds.
		$chatIds = $folder->getChatIds();

		$this->pinService->clearByFolder($folderId, $userId);
		$this->folderChatLinker->clearByFolder($folderId, $userId);

		// Guarded like the two cleanups above, and narrowed by the owner: a composition row is
		// always stored under the owner of its folder, so the predicate costs nothing.
		if ($folderId > 0)
		{
			FolderSourceTable::deleteBatch(['=FOLDER_ID' => $folderId, '=USER_ID' => $userId]);
		}

		$deleteResult = $folder->delete();
		if (!$deleteResult->isSuccess())
		{
			return $result->addErrors($deleteResult->getErrors());
		}

		(new FolderDelete($folderId, $userId))->send();

		(new FolderAnalytics($userId))->addDeleteFolder($chatIds);

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
