<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Pin;

use Bitrix\Im\Model\FolderPinTable;
use Bitrix\Im\Recent;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Im\V2\Error;
use Bitrix\Im\V2\Folder\Definition\CompositeDefinition;
use Bitrix\Im\V2\Folder\Definition\SelectionResolver;
use Bitrix\Im\V2\Folder\Error\FolderError;
use Bitrix\Im\V2\Folder\Folder;
use Bitrix\Im\V2\Folder\FolderBootstrapper;
use Bitrix\Im\V2\Folder\FolderLimits;
use Bitrix\Im\V2\Folder\FolderProvider;
use Bitrix\Im\V2\Folder\System\AllFolder;
use Bitrix\Im\V2\Folder\System\AllOpenChannelFolder;
use Bitrix\Im\V2\Pull\Event\ChatPin;
use Bitrix\Im\V2\Recent\RecentProvider;
use Bitrix\Im\V2\Recent\RecentUpdater;
use Bitrix\Im\V2\Result;

class PinService
{
	private const REMOVE_FOLDER_CHUNK_SIZE = 500;

	// Set a refused pin ran out of room in, carried by the error so that the client names what is
	// full: the folder by its own title, the project by the title of the parent chat.
	private const PIN_LIMIT_SCOPE_FOLDER = 'folder';
	private const PIN_LIMIT_SCOPE_PROJECT = 'project';

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

		// Lazy bootstrap is required for the legacy fan-out across system folders, and for the
		// primary root folder to be there at all when {@see self::resolveWriteScope()} looks for it.
		$this->folderBootstrapper->ensureSystemFolders($userId);

		// With the flag off the write stays on the legacy global path:
		//   recent.PINNED='Y' + shadow fan-out across matching folders, the $folder argument
		// ignored, so the invariant "PINNED='Y' ⇔ shadow rows in every matching folder" holds.
		if (!Features::isFolderScopedPinsAvailable($userId))
		{
			return $this->pinChatLegacy($userId, $chat);
		}

		$scope = $this->resolveWriteScope($userId, $chat, $folder);
		if ($scope === null)
		{
			return $this->pinChatGlobalOnly($userId, $chat);
		}

		// The membership gate belongs to the folder the request named; a null $folder means the
		// server picked the scope itself and the gate is skipped — {@see self::pinChatPerFolder()}.
		return $this->pinChatPerFolder($userId, $chat, $scope, checkMembership: $folder !== null);
	}

	public function unpinChat(int $userId, Chat $chat, ?Folder $folder): Result
	{
		// Owner gate of the folder the request named. On the pin path the CheckEntityAccess prefilter
		// asks it; unpin drops that prefilter, since access to the chat belongs to UnpinChatPolicy and
		// its soft fallback, and the folder argument lost the gate together with the chat. Asked ahead
		// of the feature flag: the flag is a per-user option the user switches for himself.
		if ($folder !== null)
		{
			$access = $folder->checkAccess($userId);
			if (!$access->isSuccess())
			{
				return $access;
			}
		}

		$this->folderBootstrapper->ensureSystemFolders($userId);

		// Mirror of pinChat — see the comments there.
		if (!Features::isFolderScopedPinsAvailable($userId))
		{
			return $this->unpinChatLegacy($userId, $chat);
		}

		$scope = $this->resolveWriteScope($userId, $chat, $folder);
		if ($scope === null)
		{
			return $this->unpinChatGlobalOnly($userId, $chat);
		}

		return $this->unpinChatPerFolder($userId, $chat, $scope);
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
	 * Takes the pins of a composite folder off the chats a save of its composition took out of it
	 * entirely, and for the primary root folder clears their overall pinned flag too. A chat leaves
	 * only when it matches a switched-off source and none of the sources left enabled: a pin made
	 * from the sidebar header while its source was already off sits outside the composition on
	 * purpose ({@see self::pinChatPerFolder()}) and is not taken by the save of another source.
	 * Nothing is put back when a source is switched on again, and no unpin event is sent.
	 *
	 * With folder-scoped pins off the legacy fan-out keeps the overall flag and the rows of every
	 * matching folder together, so the save leaves both alone.
	 *
	 * @param string[] $removedSourceCodes stored codes of the sources the save switched off
	 * @param string[] $remainingSourceCodes stored codes of the whole composition after the save
	 */
	public function syncShadowsOnSourcesRemove(
		Folder $folder,
		array $removedSourceCodes,
		array $remainingSourceCodes,
	): void
	{
		$folderId = (int)($folder->getId() ?? 0);
		$userId = (int)($folder->getUserId() ?? 0);
		if ($folderId <= 0 || $userId <= 0 || $removedSourceCodes === [])
		{
			return;
		}

		if (!Features::isFolderScopedPinsAvailable($userId))
		{
			return;
		}

		$pinnedChatIds = $this->pinProvider->getByFolder($folder)->getPinnedChatIds();
		if ($pinnedChatIds === [])
		{
			return;
		}

		// No owner on purpose: the stored composition is matched as is, the sources the user cannot
		// have right now included.
		$resolver = new SelectionResolver($this->recentProvider);
		$leavingChatIds = $resolver->matchingChatIds(
			(new CompositeDefinition($removedSourceCodes))->toSelection(),
			$userId,
			$pinnedChatIds,
		);
		if ($leavingChatIds === [])
		{
			return;
		}

		$keptChatIds = $resolver->matchingChatIds(
			(new CompositeDefinition($remainingSourceCodes))->toSelection(),
			$userId,
			$leavingChatIds,
		);
		$unpinnedChatIds = array_values(array_diff($leavingChatIds, $keptChatIds));
		if ($unpinnedChatIds === [])
		{
			return;
		}

		$this->syncShadowsOnRemove($folderId, $unpinnedChatIds, $userId);

		if ($folder->isPrimaryRoot())
		{
			$this->recentUpdater->setPinnedByChatIds($userId, $unpinnedChatIds, false);
		}
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
	 * One shadow row per chat under the folder, idempotent through UIX_B_IM_FOLDER_PIN_1. Written by
	 * the one path that pins a set of chats into a single folder at once
	 * ({@see self::syncShadowsOnFolderAttach()}); the caller resets PinCache, since it is the one that
	 * knows whether it had anything to write.
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

	/**
	 * Folder whose pin set the write belongs to, or null when the write carries the overall pinned
	 * flag alone. The three branches (ALG-01) leaves after the feature flag:
	 * - the request came with a folder — the gesture in a list pins into that very folder;
	 * - no folder and the chat is nested — its scope is the parent chat, which holds no root folder,
	 *   so only the overall flag moves;
	 * - no folder and the chat is a root one — its scope is the primary root folder of the user, and
	 *   the null of the single resolution point (a guest, a layout without system folders) means the
	 *   overall flag alone as well.
	 *
	 * Access is not asked here: a folder that came with the request is checked by the owner gate of
	 * its own write path - the CheckEntityAccess prefilter of the controller on the pin side, and
	 * {@see self::unpinChat()} on the unpin side, where that prefilter is off - and the primary root
	 * one by {@see FolderProvider::getPrimaryRootForUser()}, which applies the availability filter and
	 * the guest cut itself.
	 */
	private function resolveWriteScope(int $userId, Chat $chat, ?Folder $folder): ?Folder
	{
		if ($folder !== null)
		{
			return $folder;
		}

		if ($chat->hasParent())
		{
			return null;
		}

		return $this->folderProvider->getPrimaryRootForUser($userId);
	}

	/**
	 * Limit of a write that carries the overall pinned flag and no folder row of its own: the set it
	 * counts is the set the pin lands in. A chat that is pinned already skips the check, so a repeat
	 * of the pin stays the silent success (API-01) promises instead of failing on a full set.
	 *
	 * @param ?int $parentChatId pin set of that parent chat, null for the overall set of the user
	 */
	private function checkOverallPinLimit(int $userId, Chat $chat, ?int $parentChatId): Result
	{
		$result = new Result();

		$recentItem = $this->recentProvider->getItem($userId, (int)$chat->getChatId());
		if ($recentItem !== null && $recentItem->isPinned())
		{
			return $result;
		}

		$pinnedCount = $parentChatId === null
			? $this->recentProvider->countPinned($userId)
			: $this->recentProvider->countPinnedByParentChat($userId, $parentChatId)
		;

		if ($pinnedCount < FolderLimits::MAX_PINNED_CHATS)
		{
			return $result;
		}

		// Scope data only where there is a scope to name: inside a project the client says which
		// project is full, and with no scope it falls back to the general text (ERR-002).
		$scopeData = $parentChatId === null
			? []
			: [
				'scope' => self::PIN_LIMIT_SCOPE_PROJECT,
				'parentChatId' => $parentChatId,
				'limit' => FolderLimits::MAX_PINNED_CHATS,
			]
		;

		return $result->addError(new Error(FolderError::FOLDER_PINS_LIMIT_EXCEEDED, $scopeData));
	}

	private function pinChatLegacy(int $userId, Chat $chat): Result
	{
		$result = new Result();

		// Legacy keeps the one set it always counted - the overall pinned chats of the user - whatever
		// the chat is nested in, so no scope is named here.
		$limit = $this->checkOverallPinLimit($userId, $chat, null);
		if (!$limit->isSuccess())
		{
			return $limit;
		}

		// Recent-row guarantee.
		$ensureRecent = $this->ensureRecentRow($userId, $chat);
		if (!$ensureRecent->isSuccess())
		{
			return $ensureRecent;
		}

		// Direct b_im_recent.PINNED update — NOT \Bitrix\Im\Recent::pin (would loop with Task 1.12).
		$this->recentUpdater->setPinned($userId, $chat, true);

		// Fan-out shadow into all matching folders of $userId
		// (default + matching system + personal with membership; AllOpenChannelFolder skipped).
		$this->syncGlobalPinShadowToFolderPins($userId, $chat, true);

		$this->pinCache->clearByUser($userId);

		// No folder number on the legacy path: the fan-out changed the set of every matching folder
		// at once, and there is no single one to name. The overall flag is stated all the same, so
		// that a client never has to derive one fact of (EVENT-01) from the other.
		(new ChatPin($chat, true, $userId, null, true))->send();

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

		(new ChatPin($chat, false, $userId, null, false))->send();

		return $result;
	}

	/**
	 * Write of a chat whose scope holds no pin set of its own: a nested chat is pinned inside its
	 * parent chat, and a root chat of a user without a primary root folder has nowhere to keep a
	 * per-folder row. Only the overall pinned flag moves, b_im_folder_pin is left untouched on
	 * purpose — the sets of the folders belong to their own scopes and must not be rewritten from
	 * here (ALG-01).
	 */
	private function pinChatGlobalOnly(int $userId, Chat $chat): Result
	{
		$result = new Result();

		// The set the pin lands in: the pinned chats of the parent chat for a nested one, and the
		// overall pinned chats of the user for a root chat that found no primary root folder.
		$parentChatId = $chat->hasParent() ? (int)$chat->getParentChatId() : null;
		$limit = $this->checkOverallPinLimit($userId, $chat, $parentChatId);
		if (!$limit->isSuccess())
		{
			return $limit;
		}

		// Recent-row guarantee. Not one of the three actions (ALG-01) lists for this branch, and
		// asked for on purpose: RecentUpdater::setPinned() is an UPDATE of b_im_recent, so with no
		// row of the pair (user, chat) it writes nothing while the branch still returns success and
		// sends the event - the user gets a button that worked and a chat that is not pinned. Both
		// neighbouring write paths ask for the same guarantee before touching the flag and fail the
		// same way ({@see self::pinChatLegacy()}, {@see self::pinChatPerFolder()}).
		$ensureRecent = $this->ensureRecentRow($userId, $chat);
		if (!$ensureRecent->isSuccess())
		{
			return $ensureRecent;
		}

		$this->recentUpdater->setPinned($userId, $chat, true);

		$this->pinCache->clearByUser($userId);

		(new ChatPin($chat, true, $userId, null, true))->send();

		return $result;
	}

	/**
	 * Mirror of {@see self::pinChatGlobalOnly()}: clears the overall pinned flag and deletes no row
	 * of b_im_folder_pin, so the pin sets of the folders survive an unpin made outside them.
	 *
	 * No recent-row guarantee here, unlike the pin side: with no row of the pair (user, chat) there
	 * is no flag to clear, and creating one in order to unpin it would put a chat the user has
	 * dropped back into the list.
	 */
	private function unpinChatGlobalOnly(int $userId, Chat $chat): Result
	{
		$this->recentUpdater->setPinned($userId, $chat, false);

		$this->pinCache->clearByUser($userId);

		(new ChatPin($chat, false, $userId, null, false))->send();

		return new Result();
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
	 * Write into the pin set of one folder: the row of the pair (folder, user) plus, for the primary
	 * root folder only, the duplication into the overall pinned flag. No other set is touched — the
	 * rule of which folder is duplicated into the flag lives here and nowhere outside the service.
	 *
	 * The membership gate is asked for the folder the request named and skipped for the one the
	 * server resolved on its own ({@see self::resolveWriteScope()}) - a deliberate deviation from
	 * (ALG-01), which calls this write the same way in both cases. The gesture in a list of a folder
	 * names that folder, so a chat outside it is still refused with FOLDER_CHAT_NOT_MEMBER. The
	 * gesture in the sidebar header sends no folder at all and the scope becomes the primary root
	 * folder of the user, where a chat can legitimately sit outside the composition: the user
	 * switches the "Copilot" source off in the composite "All", then opens a copilot chat by a link
	 * or a push and pins it. Refusing that pin would be a regression the user sees, since the legacy
	 * path performs it silently today.
	 *
	 * The row is safe to write because visibility is decided on the read and not here: the tail and
	 * the pinned block are both cut by the Selection of the owned folder
	 * ({@see \Bitrix\Im\V2\Folder\FolderRecentProvider}), so while the source is off the pin surfaces
	 * nowhere, and switching the source back on shows the chat in "All" already pinned. The rows of
	 * the chats a save of the composition takes out of "All" go with that save
	 * ({@see self::syncShadowsOnSourcesRemove()}).
	 *
	 * Such a row takes a place in the limit all the same: the limit counts every stored row of the
	 * set, so a full folder may show fewer pins than the limit. That is the price of a set that never
	 * outgrows the pinned block of the first page once the source is switched back on.
	 *
	 * @param bool $checkMembership false only for a scope the server resolved instead of the client
	 */
	private function pinChatPerFolder(
		int $userId,
		Chat $chat,
		Folder $folder,
		bool $checkMembership,
	): Result
	{
		$result = new Result();
		$chatId = (int)$chat->getChatId();
		$folderId = (int)$folder->getId();

		// Blanket-prohibition for AllOpenChannelFolder, whatever resolved the scope.
		if ($folder instanceof AllOpenChannelFolder)
		{
			return $result->addError(new Error(FolderError::FOLDER_OPERATION_NOT_SUPPORTED));
		}

		if ($checkMembership)
		{
			$membership = $this->checkPerFolderMembership($folder, $chat);
			if (!$membership->isSuccess())
			{
				return $membership;
			}
		}

		// The stored rows answer both questions: the row of a chat outside the visible composition is
		// there to be found, which keeps the repeated pin silent, and it is counted by the limit.
		$pinState = $this->pinProvider->getByFolder($folder);
		if ($pinState->hasChat($chatId))
		{
			return $result;
		}

		if ($pinState->getPinnedChatCount() >= FolderLimits::MAX_PINNED_CHATS)
		{
			return $result->addError($this->makePinsLimitError($folderId));
		}

		// Recent-row guarantee.
		$ensureRecent = $this->ensureRecentRow($userId, $chat);
		if (!$ensureRecent->isSuccess())
		{
			return $ensureRecent;
		}

		// INSERT pin row with denormalized USER_ID + FOLDER_PARENT_ID, idempotent through
		// UIX_B_IM_FOLDER_PIN_1 like the other writer of this set ({@see self::insertShadowRows()}):
		// the idempotency check above reads a cache, so two simultaneous repeats of one pin both get
		// past it and the second must not fail on the unique index.
		FolderPinTable::multiplyInsertWithoutDuplicate(
			[[
				'FOLDER_ID' => $folderId,
				'CHAT_ID' => $chatId,
				'PIN_SORT' => 0,
				'USER_ID' => $userId,
				'FOLDER_PARENT_ID' => $folder->getParentId(),
			]],
			['UNIQUE_FIELDS' => ['FOLDER_ID', 'CHAT_ID']]
		);

		$isPrimaryRoot = $folder->isPrimaryRoot();

		// PINNED denormalization for the primary root folder only.
		if ($isPrimaryRoot)
		{
			$this->recentUpdater->setPinned($userId, $chat, true);
		}

		$this->pinCache->clearByUser($userId);

		(new ChatPin($chat, true, $userId, $folderId, $isPrimaryRoot ? true : null))->send();

		return $result;
	}

	/**
	 * Mirror of {@see self::pinChatPerFolder()}: drops the row of one folder and, for the primary
	 * root folder only, clears the overall pinned flag. The sets of the other folders are left
	 * alone, so an unpin inside one folder cannot empty a set the user keeps elsewhere.
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

		$isPrimaryRoot = $folder->isPrimaryRoot();

		// Idempotency early-return via the raw cached pin-state, as on the pin side: a row the
		// composition no longer covers has to stay removable. The primary root folder is the exception:
		// its set is the one carrying the overall pinned flag, and the flag outlives the row when the
		// folders feature moves the primary root place between "All" and "Chats". Returning here
		// would leave such a chat pinned for good, with no folder left to unpin it from. The exception
		// is asked against the flag itself, and not taken on faith: a chat that was never pinned looks
		// the same from the pin set, and healing it would cost a write, an invalidation and a push over
		// a state that is already right.
		if (!$this->pinProvider->getByFolder($folder)->hasChat($chatId))
		{
			if ($isPrimaryRoot && $this->isOverallPinned($userId, $chatId))
			{
				$this->recentUpdater->setPinned($userId, $chat, false);

				$this->pinCache->clearByUser($userId);

				// Event of the self-heal, with no folder number on purpose: no pin set of a folder
				// changed here, only the overall flag did, and by the apply rule of (EVENT-01) the
				// client has to touch exactly the fact that moved.
				(new ChatPin($chat, false, $userId, null, false))->send();
			}

			return $result;
		}

		// USER_ID is the second line behind the owner gate of {@see self::unpinChat()}: every row of
		// the table carries the owner of its folder, so a request that got past the gate still cannot
		// reach a row of another user.
		FolderPinTable::deleteBatch([
			'=FOLDER_ID' => $folderId,
			'=CHAT_ID' => $chatId,
			'=USER_ID' => $userId,
		]);

		// PINNED denormalization for the primary root folder only.
		if ($isPrimaryRoot)
		{
			$this->recentUpdater->setPinned($userId, $chat, false);
		}

		$this->pinCache->clearByUser($userId);

		(new ChatPin($chat, false, $userId, $folderId, $isPrimaryRoot ? false : null))->send();

		return $result;
	}

	/**
	 * Overall pinned flag of the pair (user, chat) as it stands now. The row is normally in the
	 * request cache of {@see RecentProvider::getItem()} by this point, so the question costs nothing.
	 */
	private function isOverallPinned(int $userId, int $chatId): bool
	{
		return $this->recentProvider->getItem($userId, $chatId)?->isPinned() === true;
	}

	/**
	 * Refusal of a per-folder write over the limit of the folder: the client is told which folder is
	 * full and by which limit.
	 */
	private function makePinsLimitError(int $folderId): Error
	{
		return new Error(FolderError::FOLDER_PINS_LIMIT_EXCEEDED, [
			'scope' => self::PIN_LIMIT_SCOPE_FOLDER,
			'folderId' => $folderId,
			'limit' => FolderLimits::MAX_PINNED_CHATS,
		]);
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
