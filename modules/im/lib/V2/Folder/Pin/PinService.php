<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Pin;

use Bitrix\Im\Model\FolderPinTable;
use Bitrix\Im\Recent;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Im\V2\Error;
use Bitrix\Im\V2\Folder\Error\FolderError;
use Bitrix\Im\V2\Folder\Folder;
use Bitrix\Im\V2\Folder\FolderBootstrapper;
use Bitrix\Im\V2\Folder\FolderProvider;
use Bitrix\Im\V2\Folder\System\AllOpenChannelFolder;
use Bitrix\Im\V2\Folder\System\DefaultFolder;
use Bitrix\Im\V2\Pull\Event\ChatPin;
use Bitrix\Im\V2\Recent\RecentProvider;
use Bitrix\Im\V2\Recent\RecentUpdater;
use Bitrix\Im\V2\Result;

class PinService
{
	private const LIMIT_GLOBAL_PINS = 45;
	public const LIMIT_PINS_PER_FOLDER = 45;

	public function __construct(
		private readonly FolderProvider $folderProvider,
		private readonly FolderBootstrapper $folderBootstrapper,
		private readonly PinCache $pinCache,
		private readonly PinProvider $pinProvider,
		private readonly RecentUpdater $recentUpdater,
		private readonly RecentProvider $recentProvider,
	)
	{
	}

	public function pinChat(int $userId, Chat $chat, ?Folder $folder): Result
	{
		// Pinning a copilot draft chat is the first user action with it: turn it
		// into a real chat before any recent row is created, otherwise the draft
		// would leak into recent while still hidden from search.
		Chat\CopilotChat::activateDraftIfNeeded($chat);

		// Lazy bootstrap is required for legacy fan-out across system folders.
		$this->folderBootstrapper->ensureSystemFolders($userId);

		// Per-folder pin is temporarily disabled until full frontend migration.
		// All pin/unpin calls go through the legacy global path:
		//   recent.PINNED='Y' + shadow fan-out across matching folders.
		// The $folder argument is silently ignored on purpose — keeps the REST
		// signature stable while the invariant
		//   "PINNED='Y' ⇔ shadow rows in every matching folder"
		// holds without per-folder writes that bypass the global counter.
		return $this->pinChatLegacy($userId, $chat);
	}

	public function unpinChat(int $userId, Chat $chat, ?Folder $folder): Result
	{
		$this->folderBootstrapper->ensureSystemFolders($userId);

		// Mirror of pinChat — see comment there. The $folder argument is
		// ignored: every unpin clears recent.PINNED + every shadow row, and a
		// single ChatPin{folderId:null} pull-event tells the frontend to drop
		// the pin marker globally.
		return $this->unpinChatLegacy($userId, $chat);
	}

	public function syncShadowsOnFolderAttach(Folder $folder, array $chatIds): void
	{
		if (empty($chatIds) || $folder instanceof AllOpenChannelFolder)
		{
			return;
		}

		$userId = (int)($folder->getUserId() ?? 0);
		if ($userId <= 0)
		{
			return;
		}

		$recent = $this->recentProvider->getItemsByChatIds($userId, $chatIds);

		$rows = [];
		foreach ($recent as $item)
		{
			if (!$item->isPinned())
			{
				continue;
			}
			$rows[] = [
				'FOLDER_ID' => $folder->getId(),
				'CHAT_ID' => $item->getChatId(),
				'PIN_SORT' => 0,
				'USER_ID' => $userId,
				'FOLDER_PARENT_ID' => $folder->getParentId(),
			];
		}

		if (empty($rows))
		{
			return;
		}

		FolderPinTable::multiplyInsertWithoutDuplicate(
			$rows,
			['UNIQUE_FIELDS' => ['FOLDER_ID', 'CHAT_ID']]
		);

		$this->pinCache->clearByUser($userId);
	}

	/**
	 * @param int[] $chatIds
	 */
	public function syncShadowsOnRemove(int $folderId, array $chatIds, ?int $userId = null): void
	{
		if ($folderId <= 0)
		{
			return;
		}

		$chatIds = Normalizer::toUniquePositiveIntegers($chatIds);
		if (empty($chatIds))
		{
			return;
		}

		FolderPinTable::deleteBatch([
			'=FOLDER_ID' => $folderId,
			'=CHAT_ID' => $chatIds,
		]);

		if ($userId !== null && $userId > 0)
		{
			$this->pinCache->clearByUser($userId);
		}
	}

	public function clearByFolder(int $folderId, ?int $userId = null): void
	{
		if ($folderId <= 0)
		{
			return;
		}

		FolderPinTable::deleteBatch(['=FOLDER_ID' => $folderId]);

		if ($userId !== null && $userId > 0)
		{
			$this->pinCache->clearByUser($userId);
		}
	}

	public function clearByUserAndChat(int $userId, int $chatId): void
	{
		if ($userId <= 0 || $chatId <= 0)
		{
			return;
		}

		FolderPinTable::deleteBatch([
			'=USER_ID' => $userId,
			'=CHAT_ID' => $chatId,
		]);

		$this->pinCache->clearByUser($userId);
	}

	public function clearByUser(int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		FolderPinTable::deleteBatch(['=USER_ID' => $userId]);

		$this->pinCache->clearByUser($userId);
	}

	private function pinChatLegacy(int $userId, Chat $chat): Result
	{
		$result = new Result();
		$chatId = (int)$chat->getChatId();

		// Limit: count(b_im_recent.PINNED='Y' for $userId) < 45, but skip if
		// this chat is already pinned (idempotent re-pin).
		$recentItem = $this->recentProvider->getItem($userId, $chatId);
		if ($recentItem === null || !$recentItem->isPinned())
		{
			$pinnedCount = $this->recentProvider->countPinned($userId);
			if ($pinnedCount >= self::LIMIT_GLOBAL_PINS)
			{
				return $result->addError(new Error(FolderError::FOLDER_PINS_LIMIT_EXCEEDED));
			}
		}

		// Recent-row guarantee.
		$ensureRecent = $this->ensureRecentRow($userId, $chat);
		if (!$ensureRecent->isSuccess())
		{
			return $result->addErrors($ensureRecent->getErrors());
		}

		// Direct b_im_recent.PINNED update — NOT \Bitrix\Im\Recent::pin (would loop with Task 1.12).
		$this->recentUpdater->setPinned($userId, $chat, true);

		// Fan-out shadow into all matching folders of $userId
		// (default + matching system + personal with membership; AllOpenChannelFolder skipped).
		$this->syncGlobalPinShadowToFolderPins($userId, $chat, true);

		$this->pinCache->clearByUser($userId);

		(new ChatPin($chat, true, $userId, null))->send();

		return $result;
	}

	private function unpinChatLegacy(int $userId, Chat $chat): Result
	{
		$result = new Result();

		// Direct b_im_recent.PINNED update — idempotent on level of DB.
		$this->recentUpdater->setPinned($userId, $chat, false);

		// Self-heal: DELETE shadow rows of (user, chat) in all folders.
		$this->syncGlobalPinShadowToFolderPins($userId, $chat, false);

		$this->pinCache->clearByUser($userId);

		(new ChatPin($chat, false, $userId, null))->send();

		return $result;
	}

	private function syncGlobalPinShadowToFolderPins(int $userId, Chat $chat, bool $pinned): void
	{
		$chatId = (int)$chat->getChatId();
		if ($chatId <= 0)
		{
			return;
		}

		if (!$pinned)
		{
			FolderPinTable::deleteBatch([
				'=USER_ID' => $userId,
				'=CHAT_ID' => $chatId,
			]);

			return;
		}

		$matchingFolders = $this->collectFoldersForGlobalShadow($userId, $chat);
		if (empty($matchingFolders))
		{
			return;
		}

		$rowsToInsert = array_map(
			static fn (Folder $folder): array => [
				'FOLDER_ID' => (int)$folder->getId(),
				'CHAT_ID' => $chatId,
				'PIN_SORT' => 0,
				'USER_ID' => $userId,
				'FOLDER_PARENT_ID' => $folder->getParentId(),
			],
			$matchingFolders,
		);

		FolderPinTable::multiplyInsertWithoutDuplicate(
			$rowsToInsert,
			['UNIQUE_FIELDS' => ['FOLDER_ID', 'CHAT_ID']]
		);
	}

	private function collectFoldersForGlobalShadow(int $userId, Chat $chat): array
	{
		$folders = $this->folderProvider->getByUser($userId);

		$matching = [];
		foreach ($folders as $folder)
		{
			if ($folder instanceof AllOpenChannelFolder)
			{
				continue;
			}

			if ($folder->containsChat($chat))
			{
				$matching[] = $folder;
			}
		}

		return $matching;
	}

	/**
	 * @internal Currently unreachable — see {@see self::pinChat()}. Kept in
	 * place for re-enablement after the frontend migrates fully off the
	 * legacy global pin model.
	 *
	 * @noinspection PhpUnusedPrivateMethodInspection
	 */
	private function pinChatPerFolder(int $userId, Chat $chat, Folder $folder): Result
	{
		$result = new Result();
		$chatId = (int)$chat->getChatId();
		$folderId = (int)$folder->getId();

		// Blanket-prohibition for AllOpenChannelFolder.
		if ($folder instanceof AllOpenChannelFolder)
		{
			return $result->addError(new Error(FolderError::FOLDER_OPERATION_NOT_SUPPORTED));
		}

		$membership = $this->checkPerFolderMembership($folder, $chat);
		if (!$membership->isSuccess())
		{
			return $result->addErrors($membership->getErrors());
		}

		// Single cached read covers both idempotency and per-folder limit.
		$pinState = $this->pinProvider->getByFolder($folder);

		if ($pinState->hasChat($chatId))
		{
			return $result;
		}

		if ($pinState->getPinnedChatCount() >= self::LIMIT_PINS_PER_FOLDER)
		{
			return $result->addError(new Error(FolderError::FOLDER_PINS_LIMIT_EXCEEDED));
		}

		// Recent-row guarantee.
		$ensureRecent = $this->ensureRecentRow($userId, $chat);
		if (!$ensureRecent->isSuccess())
		{
			return $result->addErrors($ensureRecent->getErrors());
		}

		// INSERT pin row with denormalized USER_ID + FOLDER_PARENT_ID.
		FolderPinTable::add([
			'FOLDER_ID' => $folderId,
			'CHAT_ID' => $chatId,
			'PIN_SORT' => 0,
			'USER_ID' => $userId,
			'FOLDER_PARENT_ID' => $folder->getParentId(),
		]);

		// PINNED denormalization for default folder only.
		if ($folder instanceof DefaultFolder)
		{
			$this->recentUpdater->setPinned($userId, $chat, true);
		}

		$this->pinCache->clearByUser($userId);

		(new ChatPin($chat, true, $userId, $folderId))->send();

		return $result;
	}

	/**
	 * @internal Currently unreachable — see {@see self::unpinChat()}. Kept in
	 * place for re-enablement after the frontend migrates fully off the
	 * legacy global pin model.
	 *
	 * @noinspection PhpUnusedPrivateMethodInspection
	 */
	private function unpinChatPerFolder(int $userId, Chat $chat, Folder $folder): Result
	{
		$result = new Result();
		$chatId = (int)$chat->getChatId();
		$folderId = (int)$folder->getId();

		if ($folder instanceof AllOpenChannelFolder)
		{
			return $result->addError(new Error(FolderError::FOLDER_OPERATION_NOT_SUPPORTED));
		}

		// Idempotency early-return via cached pin-state.
		if (!$this->pinProvider->getByFolder($folder)->hasChat($chatId))
		{
			return $result;
		}

		FolderPinTable::deleteBatch([
			'=FOLDER_ID' => $folderId,
			'=CHAT_ID' => $chatId,
		]);

		// PINNED denormalization for default folder only.
		if ($folder instanceof DefaultFolder)
		{
			$this->recentUpdater->setPinned($userId, $chat, false);
		}

		$this->pinCache->clearByUser($userId);

		(new ChatPin($chat, false, $userId, $folderId))->send();

		return $result;
	}

	private function checkPerFolderMembership(Folder $folder, Chat $chat): Result
	{
		$result = new Result();

		if (!$folder->containsChat($chat))
		{
			return $result->addError(new Error(FolderError::FOLDER_CHAT_NOT_MEMBER));
		}

		return $result;
	}

	private function ensureRecentRow(int $userId, Chat $chat): Result
	{
		$result = new Result();
		$chatId = (int)$chat->getChatId();
		if ($chatId <= 0)
		{
			return $result->addError(new Error(FolderError::FOLDER_NOT_FOUND));
		}

		if ($this->recentProvider->getItem($userId, $chatId) !== null)
		{
			return $result;
		}

		$relation = $chat->getRelationByUserId($userId);
		if ($relation === null)
		{
			return $result->addError(new Error(FolderError::FOLDER_CHAT_NOT_VISIBLE));
		}

		Recent::addRecent($chat, $relation);

		return $result;
	}
}
