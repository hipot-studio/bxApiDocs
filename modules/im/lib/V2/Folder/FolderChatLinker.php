<?php

namespace Bitrix\Im\V2\Folder;

use Bitrix\Im\Model\FolderChatTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Im\V2\Error;
use Bitrix\Im\V2\Folder\Cache\FolderCache;
use Bitrix\Im\V2\Folder\Eligibility\EligibleChatTypes;
use Bitrix\Im\V2\Folder\Error\FolderError;
use Bitrix\Im\V2\Folder\Pin\PinService;
use Bitrix\Im\V2\Result;

/**
 * @internal
 */
class FolderChatLinker
{
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

		// All-or-nothing: both reasons to refuse are settled before anything is written. Capacity comes
		// first, so a full folder answers with the limit error even when the set also holds an
		// ineligible chat.
		$capacityError = $this->findCapacityError($folder, count($chatIds));
		if ($capacityError !== null)
		{
			return $result->addError($capacityError);
		}

		$ineligibleChatIds = $this->findIneligibleChats($folder, $chatIds);
		if (!empty($ineligibleChatIds))
		{
			return $result->addError(
				new Error(FolderError::FOLDER_CHAT_NOT_ELIGIBLE, ['chatIds' => $ineligibleChatIds])
			);
		}

		$userId = (int)$folder->getUserId();
		$folderId = (int)$folder->getId();

		$this->persistMembership($folderId, $chatIds);
		$this->pinService->syncShadowsOnFolderAttach($folder, $chatIds);
		$this->mergeChatIdsIntoFolder($folder, $chatIds);
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

	/**
	 * Removes a chat from every personal folder of every user. b_im_folder_chat holds
	 * personal-folder membership only, so a CHAT_ID-wide delete detaches it everywhere.
	 * Used by the cascade when a root chat becomes nested (personal folders keep root chats only).
	 */
	public function clearByChat(int $chatId): void
	{
		if ($chatId <= 0)
		{
			return;
		}

		$rows = FolderChatTable::query()
			->setSelect(['FOLDER_ID', 'USER_ID' => 'FOLDER.USER_ID'])
			->where('CHAT_ID', $chatId)
			->fetchAll()
		;
		if (empty($rows))
		{
			return;
		}

		$folderIds = [];
		$userIds = [];
		foreach ($rows as $row)
		{
			$folderIds[] = (int)$row['FOLDER_ID'];
			$userIds[(int)$row['USER_ID']] = true;
		}
		$userIds = array_keys($userIds);

		$this->pinService->syncShadowsOnRemoveForFolders($folderIds, $chatId, $userIds);

		FolderChatTable::deleteBatch(['=CHAT_ID' => $chatId]);

		foreach ($userIds as $userId)
		{
			$this->folderCache->clearByUser($userId);
		}
	}

	/**
	 * Chat ids the folder does not accept. An empty result means the whole set is eligible.
	 * Every write path calls it before its own mutations, so the rule lives in one place.
	 *
	 * @param int[] $chatIds
	 * @return int[]
	 */
	public function findIneligibleChats(PersonalFolder $folder, array $chatIds): array
	{
		$chatIds = Normalizer::toUniquePositiveIntegers($chatIds);
		if (empty($chatIds))
		{
			return [];
		}

		$chats = $this->getChats($chatIds, (int)$folder->getUserId());

		// The eligible recent sections are the same for every chat of the batch, so they are resolved
		// once around the loop instead of being rebuilt on each admission check.
		return EligibleChatTypes::withRecentSectionsResolvedOnce(static function () use ($folder, $chats): array {
			$ineligible = [];
			foreach ($chats as $chatId => $chat)
			{
				if (!$folder->canAcceptChat($chat))
				{
					$ineligible[] = $chatId;
				}
			}

			return $ineligible;
		});
	}

	/**
	 * Whether the folder can take $additional chats: null means there is room. Public because a
	 * caller that writes into several folders has to ask before its first mutation, and asking has
	 * to be the very same logic as the write. A plain count comparison is not: the check first
	 * reclaims the membership rows that show up nowhere ({@see reclaimUnusableMembership}), and only
	 * then refuses.
	 */
	public function findCapacityError(PersonalFolder $folder, int $additional): ?Error
	{
		if (count($folder->getChatIds()) + $additional <= FolderLimits::MAX_CHATS_PER_FOLDER)
		{
			return null;
		}

		$this->reclaimUnusableMembership($folder);

		if (count($folder->getChatIds()) + $additional <= FolderLimits::MAX_CHATS_PER_FOLDER)
		{
			return null;
		}

		return new Error(FolderError::FOLDER_CHATS_LIMIT_EXCEEDED);
	}

	/**
	 * Chat ids stored in the folder whose membership rows can never produce a row in it. Asking only:
	 * nothing is written, so a caller that also holds an incoming composition can subtract them from
	 * it before it decides which chats the request adds, and still refuse the request without having
	 * changed anything. Taking the rows out is then the ordinary {@see deleteChats}.
	 *
	 * @return int[]
	 */
	public function findStaleMembership(PersonalFolder $folder): array
	{
		$existingChatIds = $folder->getChatIds();
		if (empty($existingChatIds))
		{
			return [];
		}

		$userId = (int)$folder->getUserId();

		$staleChatIds = [];
		foreach ($this->getChats($existingChatIds, $userId) as $chatId => $chat)
		{
			if ($this->isStaleMembership($chat, $userId))
			{
				$staleChatIds[] = $chatId;
			}
		}

		return $staleChatIds;
	}

	/**
	 * The limit path drops the rows itself, and asks about access on top of {@see isStaleMembership}:
	 * a row of a chat the owner cannot reach holds a slot they cannot use either. Access is asked for
	 * here alone - it depends on other modules and on the chat tree, so it may say no for a while, and
	 * a row must not be lost by it outside the check whose refusal that row causes.
	 */
	private function reclaimUnusableMembership(PersonalFolder $folder): void
	{
		$existingChatIds = $folder->getChatIds();
		if (empty($existingChatIds))
		{
			return;
		}

		$userId = (int)$folder->getUserId();

		$unusableChatIds = [];
		foreach ($this->getChats($existingChatIds, $userId) as $chatId => $chat)
		{
			if ($this->isStaleMembership($chat, $userId) || !$chat->checkAccess($userId)->isSuccess())
			{
				$unusableChatIds[] = $chatId;
			}
		}

		if (!empty($unusableChatIds))
		{
			$this->deleteChats($folder, $unusableChatIds);
		}
	}

	/**
	 * @param int[] $chatIds
	 * @return array<int, Chat> requested chat id => chat; a missing chat resolves to a null-object,
	 *   hence the requested id is the key and never read back from the entity.
	 */
	private function getChats(array $chatIds, int $userId): array
	{
		$chats = [];
		foreach ($chatIds as $chatId)
		{
			$chats[$chatId] = Chat::getInstance($chatId);
		}
		Chat::fillSelfRelations($chats, $userId);

		return $chats;
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

	/**
	 * A membership row of a chat that is gone, or that the owner of the folder is not in: neither can
	 * ever show a row in the folder, because a folder draws its rows from the recent list of its owner.
	 * Both facts are local to the folder and its owner and do not come back on their own. Membership is
	 * the existence of the relation: a hidden one still means the user is in the chat, so IS_HIDDEN is
	 * not asked about.
	 */
	private function isStaleMembership(Chat $chat, int $userId): bool
	{
		return !$chat->isExist() || $chat->getRelationByUserId($userId) === null;
	}
}
