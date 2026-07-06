<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder;

use Bitrix\Im\Model\FolderPinTable;
use Bitrix\Im\Model\FolderTable;
use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Folder\Cache\FolderCache;
use Bitrix\Im\V2\Folder\Pin\PinCache;
use Bitrix\Im\V2\Folder\System\AllOpenChannelFolder;
use Bitrix\Im\V2\Folder\System\DefaultFolder;
use Bitrix\Im\V2\Folder\System\SystemFolder;
use Bitrix\Im\V2\Folder\System\SystemFolderRegistry;

class FolderBootstrapper
{
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
		$prototypes = $this->systemFolderRegistry->getAll();

		$rowsToInsert = [];
		/** @var array<string, SystemFolder> $instantiated */
		$instantiated = [];
		foreach ($prototypes as $prototype)
		{
			$code = $prototype->code;
			if (isset($beforeByCode[$code]))
			{
				continue;
			}

			$systemFolder = $prototype->instantiate();
			$instantiated[$code] = $systemFolder;

			$rowsToInsert[] = [
				'USER_ID' => $userId,
				'PARENT_CHAT_ID' => 0,
				'TYPE' => SystemFolder::TYPE,
				'CODE' => $code,
				'TITLE' => null,
				'SORT' => $systemFolder->getDefaultPosition(),
			];
		}

		if (empty($rowsToInsert))
		{
			$this->ensuredUserIds[$userId] = true;

			return;
		}

		FolderTable::multiplyInsertWithoutDuplicate(
			$rowsToInsert,
			['UNIQUE_FIELDS' => ['USER_ID', 'PARENT_CHAT_ID', 'CODE']]
		);

		// Reload after upsert to get freshly-assigned ids.
		$afterByCode = $this->loadSystemFolders($userId);

		$migratedAny = false;
		foreach ($instantiated as $code => $systemFolder)
		{
			if (!isset($afterByCode[$code]))
			{
				continue;
			}

			$folderId = $afterByCode[$code];
			$inserted = $this->migrateLegacyPinsToSystemFolder($userId, $folderId, $systemFolder);
			if ($inserted)
			{
				$migratedAny = true;
			}
		}

		$this->folderCache->clearByUser($userId);
		if ($migratedAny)
		{
			$this->pinCache->clearByUser($userId);
		}

		$this->ensuredUserIds[$userId] = true;
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
	 * One-time copying of legacy global pins for $userId into the new
	 * system folder $folderId, but only for chats that match the recent
	 * section of $systemFolder.
	 *
	 * Idempotent through UIX_B_IM_FOLDER_PIN_1 (FOLDER_ID, CHAT_ID).
	 *
	 * AllOpenChannelFolder is skipped via instanceof check —
	 * b_im_folder_pin(allOpenChannelFolderId, *) is contractually empty.
	 *
	 * TODO: remove this legacy/global pin migration branch after clients
	 * switch from global pins to scoped folder pins.
	 *
	 * @return bool true if at least one row was inserted.
	 */
	private function migrateLegacyPinsToSystemFolder(int $userId, int $folderId, SystemFolder $systemFolder): bool
	{
		if ($systemFolder instanceof AllOpenChannelFolder)
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
}
