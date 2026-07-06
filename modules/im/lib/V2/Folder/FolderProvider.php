<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder;

use Bitrix\Im\Model\FolderChatTable;
use Bitrix\Im\Model\FolderTable;
use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Im\V2\Folder\Cache\FolderCache;
use Bitrix\Im\V2\Folder\Cache\FolderListData;

class FolderProvider
{
	public function __construct(
		private readonly FolderCache $folderCache,
		private readonly FolderBootstrapper $folderBootstrapper,
		private readonly FolderFactory $folderFactory,
	)
	{
	}

	public function getById(int $folderId): ?Folder
	{
		if ($folderId <= 0)
		{
			return null;
		}

		$row = FolderTable::query()
			->setSelect(['*'])
			->where('ID', $folderId)
			->fetch()
		;

		if (!$row)
		{
			return null;
		}

		$folder = $this->folderFactory->createFromRow($row);
		if ($folder === null)
		{
			return null;
		}

		if ($folder instanceof PersonalFolder)
		{
			$folder->setChatIds($this->loadChatIdsForFolder((int)$row['ID']));
		}

		return $folder;
	}

	public function getByUser(int $userId, int $parentId = 0): FolderCollection
	{
		$collection = new FolderCollection();

		if ($userId <= 0)
		{
			return $collection;
		}

		// FolderCache lookup keyed by (userId, parentId).
		$cached = $this->folderCache->get($userId, $parentId);

		if ($cached !== null)
		{
			return $this->hydrateFromCacheData($cached);
		}

		// Cache miss: lazy init system folders + MIG-01 (legacy pin migration).
		$this->folderBootstrapper->ensureSystemFolders($userId);

		$rows = FolderTable::query()
			->setSelect(['*'])
			->where('USER_ID', $userId)
			->where('PARENT_CHAT_ID', $parentId)
			->setOrder(['SORT' => 'ASC', 'ID' => 'ASC'])
			->fetchAll()
		;

		if (empty($rows))
		{
			return $collection;
		}

		// Resolve all rows into concrete subclasses, collect personal ids for batch chat preload.
		$resolved = [];
		$personalIds = [];

		foreach ($rows as $row)
		{
			$folder = $this->folderFactory->createFromRow($row);
			if ($folder === null)
			{
				continue;
			}

			$resolved[(int)$row['ID']] = $folder;

			if ($folder instanceof PersonalFolder)
			{
				$personalIds[] = (int)$row['ID'];
			}
		}

		// Batch preload membership for personal folders — single SQL, no N+1.
		$chatIdsByFolder = $this->loadChatIdsForFolders($personalIds);

		// Build FolderListData for cache storage (flat rows + chatIds map).
		$flatRows = [];
		foreach ($rows as $row)
		{
			$flatRows[(int)$row['ID']] = $row;
		}
		$this->folderCache->set($userId, $parentId, new FolderListData($flatRows, $chatIdsByFolder));

		foreach ($resolved as $folderId => $folder)
		{
			if ($folder instanceof PersonalFolder)
			{
				$folder->setChatIds($chatIdsByFolder[$folderId] ?? []);
			}

			$collection->add($folder);
		}

		return $collection;
	}

	/**
	 * Hydrates a FolderCollection from cached flat rows.
	 */
	private function hydrateFromCacheData(FolderListData $data): FolderCollection
	{
		$collection = new FolderCollection();

		foreach ($data->folders as $folderId => $row)
		{
			$folder = $this->folderFactory->createFromRow($row);
			if ($folder === null)
			{
				continue;
			}

			if ($folder instanceof PersonalFolder)
			{
				$folder->setChatIds($data->personalChatIds[$folderId] ?? []);
			}

			$collection->add($folder);
		}

		return $collection;
	}

	/**
	 * @param int[] $ids
	 * @return array<int, Folder> map folderId → Folder
	 */
	public function getByIds(array $ids): array
	{
		$ids = Normalizer::toUniquePositiveIntegers($ids);

		if (empty($ids))
		{
			return [];
		}

		$rows = FolderTable::query()
			->setSelect(['*'])
			->whereIn('ID', $ids)
			->fetchAll()
		;

		/** @var array<int, Folder> $result */
		$result = [];
		$personalIds = [];
		foreach ($rows as $row)
		{
			$folder = $this->folderFactory->createFromRow($row);
			if ($folder === null)
			{
				continue;
			}

			$folderId = (int)$row['ID'];
			$result[$folderId] = $folder;

			if ($folder instanceof PersonalFolder)
			{
				$personalIds[] = $folderId;
			}
		}

		if (!empty($personalIds))
		{
			$chatIdsByFolder = $this->loadChatIdsForFolders($personalIds);
			foreach ($personalIds as $folderId)
			{
				/** @var PersonalFolder $folder */
				$folder = $result[$folderId];
				$folder->setChatIds($chatIdsByFolder[$folderId] ?? []);
			}
		}

		return $result;
	}

	/**
	 * @return int[]
	 */
	private function loadChatIdsForFolder(int $folderId): array
	{
		if ($folderId <= 0)
		{
			return [];
		}

		return $this->loadChatIdsForFolders([$folderId])[$folderId] ?? [];
	}

	/**
	 * @param int[] $folderIds
	 * @return array<int, int[]> map folderId → chatIds
	 */
	private function loadChatIdsForFolders(array $folderIds): array
	{
		$result = [];

		if (empty($folderIds))
		{
			return $result;
		}

		$rows = FolderChatTable::query()
			->setSelect(['FOLDER_ID', 'CHAT_ID'])
			->whereIn('FOLDER_ID', $folderIds)
			->fetchAll()
		;

		foreach ($rows as $row)
		{
			$folderId = (int)$row['FOLDER_ID'];
			$chatId = (int)$row['CHAT_ID'];
			$result[$folderId][] = $chatId;
		}

		return $result;
	}

	public function findFolderIdsByUser(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$rows = FolderTable::query()
			->setSelect(['ID'])
			->where('USER_ID', $userId)
			->fetchAll()
		;

		$result = [];
		foreach ($rows as $row)
		{
			$result[] = (int)$row['ID'];
		}

		return $result;
	}
}
