<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder;

use Bitrix\Im\Model\FolderPinTable;
use Bitrix\Im\Model\FolderSourceTable;
use Bitrix\Im\Model\FolderTable;
use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Folder\Cache\FolderCache;
use Bitrix\Im\V2\Folder\Pin\PinCache;
use Bitrix\Im\V2\Folder\System\AllFolder;
use Bitrix\Im\V2\Folder\System\DefaultFolder;
use Bitrix\Im\V2\Folder\System\SystemFolder;
use Bitrix\Im\V2\Folder\System\SystemFolderPrototype;
use Bitrix\Im\V2\Folder\System\SystemFolderRegistry;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;

class FolderBootstrapper
{
	private const LOCK_TIMEOUT = 3;

	/**
	 * Slot that makes a folder come first for the user. Every existing SORT is >= 0, so
	 * shifting from this slot moves all of them and leaves the new row alone in it.
	 * {@see AllFolder::getDefaultPosition} carries the same value for the fresh-user batch.
	 */
	private const FIRST_POSITION = 0;

	private array $ensuredUserIds = [];

	public function __construct(
		private readonly FolderCache $folderCache,
		private readonly PinCache $pinCache,
		private readonly SystemFolderRegistry $systemFolderRegistry,
	)
	{
	}

	public function ensureSystemFolders(int $userId): void
	{
		if ($userId <= 0 || isset($this->ensuredUserIds[$userId]))
		{
			return;
		}

		// Snapshot "before" — used to detect newly created system folders for migration.
		$beforeByCode = $this->loadSystemFolders($userId);
		$missingPrototypes = $this->findMissingPrototypes($beforeByCode);

		if ($missingPrototypes === [])
		{
			// A repaired composition is invisible to a warm cache holding the empty one.
			if ($this->seedAllFolderSources($userId, $beforeByCode[AllFolder::CODE] ?? null))
			{
				$this->folderCache->clearByUser($userId);
			}

			$this->ensuredUserIds[$userId] = true;

			return;
		}

		// An empty "before" means the user has no top-level rows at all: a personal folder
		// cannot appear earlier, because FolderService::create() bootstraps system folders
		// through FolderProvider::getByUser() before inserting its own row. Such a user needs
		// no shifting - "All" is first through its default position.
		$isFreshUser = $beforeByCode === [];
		$allFolderId = null;

		if ($isFreshUser)
		{
			$this->batchInsertSystemFolders($userId, $missingPrototypes);
		}
		else
		{
			$allPrototype = $missingPrototypes[AllFolder::CODE] ?? null;
			$batchPrototypes = $missingPrototypes;
			unset($batchPrototypes[AllFolder::CODE]);

			// Batch first: those rows must be shifted together with the already existing ones.
			$this->batchInsertSystemFolders($userId, $batchPrototypes);

			if ($allPrototype !== null)
			{
				$allFolderId = $this->insertSystemFolderAtPosition($userId, $allPrototype, self::FIRST_POSITION);
				if ($allFolderId === null)
				{
					// "All" was not created (lock not acquired or the insert failed) and the memo stays
					// unset, so a retry is possible - but not on the read path: FolderProvider caches the
					// list without "All" until FolderCache expires or is cleared. Soft degradation - the
					// guaranteed retries come from pin, unpin and sort, which do not read that cache.
					return;
				}
			}
		}

		// Reload after insert to get freshly-assigned ids.
		$afterByCode = $this->loadSystemFolders($userId);
		if ($allFolderId !== null)
		{
			$afterByCode[AllFolder::CODE] = $allFolderId;
		}

		$migratedAnyPin = $this->migrateLegacyPins($userId, $missingPrototypes, $afterByCode);

		$this->seedAllFolderSources($userId, $afterByCode[AllFolder::CODE] ?? null);

		$this->folderCache->clearByUser($userId);
		if ($migratedAnyPin)
		{
			$this->pinCache->clearByUser($userId);
		}

		$this->ensuredUserIds[$userId] = true;
	}

	/**
	 * @param array<string, int> $existingByCode code -> folderId
	 * @return array<string, SystemFolderPrototype> code -> prototype, in canonical registry order
	 */
	private function findMissingPrototypes(array $existingByCode): array
	{
		$missing = [];
		foreach ($this->systemFolderRegistry->getAll() as $prototype)
		{
			if (!isset($existingByCode[$prototype->code]))
			{
				$missing[$prototype->code] = $prototype;
			}
		}

		return $missing;
	}

	/**
	 * @param array<string, SystemFolderPrototype> $prototypes
	 */
	private function batchInsertSystemFolders(int $userId, array $prototypes): void
	{
		$rowsToInsert = [];
		foreach ($prototypes as $code => $prototype)
		{
			$rowsToInsert[] = [
				'USER_ID' => $userId,
				'PARENT_CHAT_ID' => 0,
				'TYPE' => SystemFolder::TYPE,
				'CODE' => $code,
				'TITLE' => null,
				'SORT' => $prototype->instantiate()->getDefaultPosition(),
			];
		}

		FolderTable::multiplyInsertWithoutDuplicate(
			$rowsToInsert,
			['UNIQUE_FIELDS' => ['USER_ID', 'PARENT_CHAT_ID', 'CODE']]
		);
	}

	/**
	 * Inserts the system folder $prototype for $userId at $position, shifting every
	 * top-level row of that user with SORT >= $position by +1.
	 *
	 * The shift is not idempotent (two parallel tabs would give +2), so the named
	 * per-user lock and the re-check of existence under it are both mandatory.
	 *
	 * No transaction is needed: each statement is atomic on its own in autocommit and the
	 * lock covers concurrency. Shift-then-insert is deliberate - if the request dies between
	 * the two statements, the next bootstrap still does not find the folder, repeats the
	 * shift and completes the insert, so the folder ends up first anyway; the price is a
	 * cosmetic drift of SORT values, erased by the first reorder. The reverse order would
	 * leave the folder sharing a neighbour's SORT with no chance of self-repair.
	 *
	 * $position is a value in SORT space, not the Nth visible tab: exactness is guaranteed
	 * for position 0 only, and no other value is used today.
	 *
	 * @return int|null folder id, or null when the folder was not created - the lock was not
	 *                 acquired or the insert itself failed
	 */
	private function insertSystemFolderAtPosition(int $userId, SystemFolderPrototype $prototype, int $position): ?int
	{
		$connection = Application::getConnection();
		$lockName = "im_folder_bootstrap_{$userId}";

		if (!$connection->lock($lockName, self::LOCK_TIMEOUT))
		{
			return null;
		}

		try
		{
			$existingByCode = $this->loadSystemFolders($userId);
			if (isset($existingByCode[$prototype->code]))
			{
				// A concurrent request was faster: no shift, the folder is already placed.
				return $existingByCode[$prototype->code];
			}

			FolderTable::updateByFilter(
				['=USER_ID' => $userId, '=PARENT_CHAT_ID' => 0, '>=SORT' => $position],
				['SORT' => new SqlExpression('?# + 1', 'SORT')]
			);

			$addResult = FolderTable::add([
				'USER_ID' => $userId,
				'PARENT_CHAT_ID' => 0,
				'TYPE' => SystemFolder::TYPE,
				'CODE' => $prototype->code,
				'TITLE' => null,
				'SORT' => $position,
			]);

			if (!$addResult->isSuccess())
			{
				// (int)null gives 0, and a zero passes every "was it created?" check downstream:
				// the composition and the pin shadows would go under FOLDER_ID = 0, holding keys
				// of the unique indexes every user shares.
				return null;
			}

			return (int)$addResult->getId();
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	/**
	 * One-time legacy pin migration for the folders created in this bootstrap call. Unlike
	 * the source composition it must NOT run for pre-existing rows: it is idempotent through
	 * the pin unique index, so a repeated pass would resurrect shadows of pins the user has
	 * since removed.
	 *
	 * @param array<string, SystemFolderPrototype> $createdPrototypes
	 * @param array<string, int> $foldersByCode code -> folderId
	 * @return bool true if at least one pin row was inserted
	 */
	private function migrateLegacyPins(int $userId, array $createdPrototypes, array $foldersByCode): bool
	{
		$migratedAny = false;
		foreach ($createdPrototypes as $code => $prototype)
		{
			$folderId = $foldersByCode[$code] ?? null;
			if ($folderId === null)
			{
				continue;
			}

			$systemFolder = $prototype->instantiate();
			$inserted = $systemFolder instanceof AllFolder
				? $this->migrateLegacyPinsToAllFolder($userId, $folderId, $systemFolder)
				: $this->migrateLegacyPinsToSystemFolder($userId, $folderId, $systemFolder);
			if ($inserted)
			{
				$migratedAny = true;
			}
		}

		return $migratedAny;
	}

	/**
	 * @return array<string, int> code → folderId
	 */
	private function loadSystemFolders(int $userId): array
	{
		$rows = FolderTable::query()
			->setSelect(['ID', 'CODE'])
			->where('USER_ID', $userId)
			->where('TYPE', SystemFolder::TYPE)
			->where('PARENT_CHAT_ID', 0)
			->fetchAll()
		;

		$result = [];
		foreach ($rows as $row)
		{
			$result[(string)$row['CODE']] = (int)$row['ID'];
		}

		return $result;
	}

	/**
	 * Writes the initial source composition of the "All" folder: one b_im_folder_source row
	 * per source flagged inInitialConfig in {@see AllFolder::getDefaultDefinition} (including
	 * the mandatory "chat" and regardless of per-source availability).
	 *
	 * Only a composition with no stored rows at all is written. Completing a non-empty one
	 * with the codes it lacks would fight the user: turning a source off is an ordinary
	 * action now and it deletes the row, while this seed runs on every read of the folder
	 * list - the source would be back by the next request.
	 *
	 * Still not a creation-only seed: a folder whose composition was never written (say the
	 * code arrived before the updater created b_im_folder_source) is in the "before" snapshot
	 * on every later call, so it would stay empty forever - and an empty CompositeDefinition
	 * means an empty selection, an empty list and a zero counter.
	 *
	 * "No rows at all" tells that folder from a user who turned everything off only because
	 * the mandatory "chat" is always appended by the server on save: any composition the user
	 * has ever stored keeps at least one row. Lose that guarantee and this seed starts
	 * resurrecting the whole registry.
	 *
	 * The price is that a new source no longer rolls out by itself - existing users keep the
	 * composition they have. The next author of the registry picks the branch: enabled for
	 * everyone needs a separate migration adding the code to non-empty compositions; enabled
	 * for new users only needs nothing beyond inInitialConfig; off for everyone means not
	 * setting that flag. The settings screen lists the source for everyone either way - its
	 * items come from the canonical registry, and the stored composition only decides where
	 * the toggle stands.
	 *
	 * @param int|null $allFolderId null when the user has no "All" row yet
	 * @return bool true when rows were sent to the database
	 */
	private function seedAllFolderSources(int $userId, ?int $allFolderId): bool
	{
		if ($allFolderId === null)
		{
			return false;
		}

		if ($this->loadStoredSourceCodes($allFolderId) !== [])
		{
			return false;
		}

		$rowsToInsert = [];
		foreach ($this->initialAllFolderSourceCodes() as $code)
		{
			$rowsToInsert[] = [
				'FOLDER_ID' => $allFolderId,
				'USER_ID' => $userId,
				'SOURCE_FOLDER_CODE' => $code,
			];
		}

		if (empty($rowsToInsert))
		{
			return false;
		}

		// Parallel requests of one user can all see the empty composition and write it
		// together, racing over the same keys of UIX_B_IM_FOLDER_SOURCE_1.
		FolderSourceTable::multiplyInsertWithoutDuplicate(
			$rowsToInsert,
			['UNIQUE_FIELDS' => ['FOLDER_ID', 'SOURCE_FOLDER_CODE'], 'DEADLOCK_SAFE' => true]
		);

		return true;
	}

	/**
	 * @return array<string, true> stored source codes of the folder, as a lookup map
	 */
	private function loadStoredSourceCodes(int $allFolderId): array
	{
		$rows = FolderSourceTable::query()
			->setSelect(['SOURCE_FOLDER_CODE'])
			->where('FOLDER_ID', $allFolderId)
			->fetchAll()
		;

		$storedCodes = [];
		foreach ($rows as $row)
		{
			$storedCodes[(string)$row['SOURCE_FOLDER_CODE']] = true;
		}

		return $storedCodes;
	}

	/**
	 * One-time copying of legacy global pins for $userId into the new
	 * system folder $folderId, but only for chats that match the recent
	 * section of $systemFolder.
	 *
	 * Idempotent through UIX_B_IM_FOLDER_PIN_1 (FOLDER_ID, CHAT_ID).
	 *
	 * Folders without a matching legacy recent section opt out before pinned rows are loaded.
	 *
	 * TODO: remove this legacy/global pin migration branch after clients
	 * switch from global pins to scoped folder pins.
	 *
	 * @return bool true if at least one row was inserted.
	 */
	private function migrateLegacyPinsToSystemFolder(int $userId, int $folderId, SystemFolder $systemFolder): bool
	{
		if (!$systemFolder->shouldMigrateLegacyPins())
		{
			return false;
		}

		$pinnedRows = RecentTable::query()
			->setSelect(['ITEM_CID'])
			->where('USER_ID', $userId)
			->where('PINNED', 'Y')
			->fetchAll()
		;

		if (empty($pinnedRows))
		{
			return false;
		}

		$matchingChatIds = [];
		$systemSection = $systemFolder->getRecentSection();
		$isDefault = $systemFolder instanceof DefaultFolder;

		foreach ($pinnedRows as $row)
		{
			$chatId = (int)($row['ITEM_CID'] ?? 0);
			if ($chatId <= 0)
			{
				continue;
			}

			if ($isDefault)
			{
				$matchingChatIds[] = $chatId;
				continue;
			}

			$chat = Chat::getInstance($chatId);
			if (!$chat->isExist())
			{
				continue;
			}
			$sections = $chat->getRecentSections();

			if (in_array($systemSection, $sections, true))
			{
				$matchingChatIds[] = $chatId;
			}
		}

		if (empty($matchingChatIds))
		{
			return false;
		}

		$parentId = $systemFolder->getParentId();
		$rowsToInsert = [];
		foreach ($matchingChatIds as $chatId)
		{
			$rowsToInsert[] = [
				'FOLDER_ID' => $folderId,
				'CHAT_ID' => $chatId,
				'PIN_SORT' => 0,
				'USER_ID' => $userId,
				'FOLDER_PARENT_ID' => $parentId,
			];
		}

		FolderPinTable::multiplyInsertWithoutDuplicate(
			$rowsToInsert,
			['UNIQUE_FIELDS' => ['FOLDER_ID', 'CHAT_ID']]
		);

		return true;
	}

	/**
	 * One-time copy of legacy global pins into a freshly-created "All" folder,
	 * limited to chats that belong to its initial composition. Uses the same
	 * membership predicate as the live global-pin fan-out
	 * ({@see \Bitrix\Im\V2\Folder\Pin\PinService}), so pre-existing pins behave
	 * exactly like pins created after rollout. Shadows live under the "All"
	 * folder id (the composite tail reads its own pin scope).
	 *
	 * Idempotent through UIX_B_IM_FOLDER_PIN_1 (FOLDER_ID, CHAT_ID).
	 *
	 * @return bool true if at least one row was inserted.
	 */
	private function migrateLegacyPinsToAllFolder(int $userId, int $allFolderId, AllFolder $allFolder): bool
	{
		$pinnedRows = RecentTable::query()
			->setSelect(['ITEM_CID'])
			->where('USER_ID', $userId)
			->where('PINNED', 'Y')
			->fetchAll()
		;

		if (empty($pinnedRows))
		{
			return false;
		}

		// The prototype instance deliberately carries no owner, so membership resolves
		// against the full composition: bootstrap is never gated by source availability.
		$allFolder->setSourceCodes($this->initialAllFolderSourceCodes());

		$matchingChatIds = [];
		foreach ($pinnedRows as $row)
		{
			$chatId = (int)($row['ITEM_CID'] ?? 0);
			if ($chatId <= 0)
			{
				continue;
			}

			$chat = Chat::getInstance($chatId);
			if (!$chat->isExist())
			{
				continue;
			}

			if ($allFolder->containsChat($chat))
			{
				$matchingChatIds[] = $chatId;
			}
		}

		if (empty($matchingChatIds))
		{
			return false;
		}

		$parentId = $allFolder->getParentId();
		$rowsToInsert = [];
		foreach ($matchingChatIds as $chatId)
		{
			$rowsToInsert[] = [
				'FOLDER_ID' => $allFolderId,
				'CHAT_ID' => $chatId,
				'PIN_SORT' => 0,
				'USER_ID' => $userId,
				'FOLDER_PARENT_ID' => $parentId,
			];
		}

		FolderPinTable::multiplyInsertWithoutDuplicate(
			$rowsToInsert,
			['UNIQUE_FIELDS' => ['FOLDER_ID', 'CHAT_ID']]
		);

		return true;
	}

	/**
	 * Source codes of the initial "All" composition, in canonical registry order.
	 *
	 * @return string[]
	 */
	private function initialAllFolderSourceCodes(): array
	{
		$codes = [];
		foreach (AllFolder::getDefaultDefinition() as $source)
		{
			if ($source->inInitialConfig)
			{
				$codes[] = $source->code;
			}
		}

		return $codes;
	}
}
