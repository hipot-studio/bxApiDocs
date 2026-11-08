<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Pin;

use Bitrix\Im\Model\FolderPinTable;
use Bitrix\Im\Recent;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Im\V2\Error;
use Bitrix\Im\V2\Folder\Definition\CompositeDefinition;
use Bitrix\Im\V2\Folder\Definition\SelectionResolver;
use Bitrix\Im\V2\Folder\Error\FolderError;
use Bitrix\Im\V2\Folder\Folder;
use Bitrix\Im\V2\Folder\FolderBootstrapper;
use Bitrix\Im\V2\Folder\FolderProvider;
use Bitrix\Im\V2\Folder\System\AllFolder;
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
	private const REMOVE_FOLDER_CHUNK_SIZE = 500;

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
		Chat\CopilotChat::activateDraftIfNeeded($chat, initiatorId: $userId);

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

		$pinnedChatIds = [];
		foreach ($recent as $item)
		{
			if ($item->isPinned())
			{
				$pinnedChatIds[] = $item->getChatId();
			}
		}

		if (empty($pinnedChatIds))
		{
			return;
		}

		$this->insertShadowRows($folder, $userId, $pinnedChatIds);

		$this->pinCache->clearByUser($userId);
	}

	/**
	 * Shadow hygiene when sources are toggled off in a composite folder (ALG-03
	 * syncShadowsOnRemove(folder, sourceCodes)): drops the folder's b_im_folder_pin
	 * shadows whose chat belongs to one of the removed sources, then resets PinCache.
	 * Called by {@see \Bitrix\Im\V2\Folder\Source\FolderSourceService::updateSources()}; the
	 * folded-Selection guard in {@see \Bitrix\Im\V2\Folder\FolderRecentProvider}
	 * already hides such shadows, this physically garbage-collects them.
	 *
	 * The whole set of one save at once, not a call per source: PinCache::clearByUser is a real
	 * tag invalidation, so a per-source loop would read the pins cold on every step and repeat
	 * the recent query as many times as sources were switched off.
	 *
	 * Named *OnSourcesRemove to avoid colliding with the chat-list primitive
	 * {@see self::syncShadowsOnRemove()} it delegates to.
	 *
	 * @param string[] $sourceCodes stored codes of the sources that left the composition
	 */
	public function syncShadowsOnSourcesRemove(Folder $folder, array $sourceCodes): void
	{
		$folderId = (int)($folder->getId() ?? 0);
		$userId = (int)($folder->getUserId() ?? 0);
		$sourceCodes = array_values(array_filter(
			$sourceCodes,
			static fn ($code): bool => is_string($code) && $code !== '',
		));
		if ($folderId <= 0 || $userId <= 0 || $sourceCodes === [])
		{
			return;
		}

		$pinnedShadowIds = $this->pinProvider->getByFolder($folder)->getPinnedChatIds();
		if ($pinnedShadowIds === [])
		{
			return;
		}

		// No owner on purpose: garbage collection must still resolve a source the user
		// can no longer see, otherwise its shadows would stay behind forever.
		$selection = (new CompositeDefinition($sourceCodes))->toSelection();

		// The shadows themselves narrow the read: the removed sections are matched against at
		// most LIMIT_PINS_PER_FOLDER chats instead of being scanned whole.
		$staleShadowChatIds = (new SelectionResolver($this->recentProvider))
			->matchingChatIds($selection, $userId, $pinnedShadowIds)
		;

		$this->syncShadowsOnRemove($folderId, $staleShadowChatIds, $userId);
	}

	/**
	 * Add-side of the same hygiene: writes the folder's b_im_folder_pin shadows for the globally
	 * pinned chats that belong to the composition the save left enabled, then resets PinCache.
	 * Without it a pin was lost for good - switching a source off collected the shadows of its
	 * chats and switching it back on wrote none.
	 *
	 * The source of truth of a pin is b_im_recent.PINNED='Y' and not the shadows of the folder,
	 * whose set drifts both ways, so the candidates are the globally pinned chats of the user
	 * ({@see RecentProvider::getPinnedChatIds()}) - a set already capped by the global pin limit.
	 *
	 * The whole enabled composition is resolved, not the sources this save switched on: the remove
	 * side matches by source codes, so it collects the shadow of a chat belonging to a removed and
	 * a surviving source at once, and only a pass over the full composition brings such a shadow
	 * back within the same save.
	 *
	 * Neither the per-folder pin limit nor a sort order is computed (PIN_SORT = 0): the shadows of
	 * a composite folder are a subset of the global pins, which carry their own limit; the
	 * neighbouring shadow writers check no limit either; and the read cuts the pinned block by
	 * LIMIT_PINS_PER_FOLDER anyway ({@see \Bitrix\Im\V2\Folder\FolderRecentProvider}). Writing the
	 * same shadow again - a repeated save, two tabs, a source switched on twice - is safe through
	 * the unique index UIX_B_IM_FOLDER_PIN_1.
	 *
	 * @param string[] $sourceCodes stored codes of the sources the composition keeps enabled
	 */
	public function syncShadowsOnSourcesEnabled(Folder $folder, array $sourceCodes): void
	{
		$folderId = (int)($folder->getId() ?? 0);
		$userId = (int)($folder->getUserId() ?? 0);
		$sourceCodes = array_values(array_filter(
			$sourceCodes,
			static fn ($code): bool => is_string($code) && $code !== '',
		));
		if ($folderId <= 0 || $userId <= 0 || $sourceCodes === [])
		{
			return;
		}

		$pinnedChatIds = $this->recentProvider->getPinnedChatIds($userId);
		if ($pinnedChatIds === [])
		{
			return;
		}

		// No owner on purpose, as on the remove side: a source unavailable to the user is part of
		// the stored composition all the same, and its pins must not stay outside the folder.
		$selection = (new CompositeDefinition($sourceCodes))->toSelection();

		// The global pins narrow the read the same way the shadows narrow it there.
		$memberChatIds = (new SelectionResolver($this->recentProvider))
			->matchingChatIds($selection, $userId, $pinnedChatIds)
		;
		if ($memberChatIds === [])
		{
			return;
		}

		$this->insertShadowRows($folder, $userId, $memberChatIds);

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

	/**
	 * Batch counterpart of {@see self::syncShadowsOnRemove()} for detaching a single chat from
	 * many personal folders at once: one chunked DELETE over (CHAT_ID, FOLDER_ID IN ...) plus one
	 * pin-cache reset per unique user. Only shadows in the passed folders are removed; shadows in
	 * default/system folders (whose FOLDER_ID is not in $folderIds) stay intact.
	 *
	 * @param int[] $folderIds
	 * @param int[] $userIds
	 */
	public function syncShadowsOnRemoveForFolders(array $folderIds, int $chatId, array $userIds = []): void
	{
		if ($chatId <= 0)
		{
			return;
		}

		$folderIds = Normalizer::toUniquePositiveIntegers($folderIds);
		if (empty($folderIds))
		{
			return;
		}

		foreach (array_chunk($folderIds, self::REMOVE_FOLDER_CHUNK_SIZE) as $chunk)
		{
			FolderPinTable::deleteBatch([
				'=CHAT_ID' => $chatId,
				'=FOLDER_ID' => $chunk,
			]);
		}

		foreach (Normalizer::toUniquePositiveIntegers($userIds) as $userId)
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

	/**
	 * One shadow row per chat under the folder, idempotent through UIX_B_IM_FOLDER_PIN_1. Shared by
	 * the two paths that pin a set of chats into a single folder; the caller resets PinCache, since
	 * it is the one that knows whether it had anything to write.
	 *
	 * @param int[] $chatIds non-empty
	 */
	private function insertShadowRows(Folder $folder, int $userId, array $chatIds): void
	{
		$parentId = $folder->getParentId();

		$rows = [];
		foreach ($chatIds as $chatId)
		{
			$rows[] = [
				'FOLDER_ID' => $folder->getId(),
				'CHAT_ID' => $chatId,
				'PIN_SORT' => 0,
				'USER_ID' => $userId,
				'FOLDER_PARENT_ID' => $parentId,
			];
		}

		FolderPinTable::multiplyInsertWithoutDuplicate(
			$rows,
			['UNIQUE_FIELDS' => ['FOLDER_ID', 'CHAT_ID']]
		);
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

			if ($this->isShadowMember($folder, $chat))
			{
				$matching[] = $folder;
			}
		}

		return $matching;
	}

	/**
	 * Membership as the write path needs it: the composite "All" is asked without an owner,
	 * so it answers against its whole stored composition instead of the sources available to
	 * the user right now. With the owner in place a pin made while the release gate is closed
	 * leaves no shadow, while unpin still deletes shadows in every folder - the set could only
	 * shrink, and opening the gate would show a tab with the freshest pins missing.
	 *
	 * Same rule as the two neighbouring write paths, which pass no owner either
	 * ({@see self::syncShadowsOnSourcesRemove()},
	 * {@see FolderBootstrapper::migrateLegacyPinsToAllFolder()}). Reading stays filtered: the
	 * tail and the pinned block are both constrained by the Selection of the owned instance
	 * ({@see \Bitrix\Im\V2\Folder\FolderRecentProvider}), so a shadow of an unavailable source
	 * cannot surface.
	 */
	private function isShadowMember(Folder $folder, Chat $chat): bool
	{
		if ($folder instanceof AllFolder)
		{
			$unowned = clone $folder;
			$unowned->setUserId(null);

			return $unowned->containsChat($chat);
		}

		return $folder->containsChat($chat);
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
