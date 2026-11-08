<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder;

use Bitrix\Im\Model\FolderChatTable;
use Bitrix\Im\Model\FolderSourceTable;
use Bitrix\Im\Model\FolderTable;
use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Im\V2\Folder\Cache\FolderCache;
use Bitrix\Im\V2\Folder\Cache\FolderListData;
use Bitrix\Im\V2\Folder\Definition\CompositeDefinition;
use Bitrix\Im\V2\Folder\System\AllFolder;

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
		elseif ($this->isCompositeFolder($folder))
		{
			$this->hydrateComposition($folder, $this->loadSourceCodesForFolder((int)$row['ID']));
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

		// Resolve all rows into concrete subclasses, collect ids for batch preloads.
		$resolved = [];
		$personalIds = [];
		$compositeIds = [];

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
			elseif ($this->isCompositeFolder($folder))
			{
				$compositeIds[] = (int)$row['ID'];
			}
		}

		// Batch preload membership for personal folders — single SQL, no N+1.
		$chatIdsByFolder = $this->loadChatIdsForFolders($personalIds);

		// Batch preload source composition for composite folders - single SQL, no N+1.
		$sourceCodesByFolder = $this->loadSourceCodesForFolders($compositeIds);

		// Build FolderListData for cache storage (flat rows + chatIds map + source codes map).
		$flatRows = [];
		foreach ($rows as $row)
		{
			$flatRows[(int)$row['ID']] = $row;
		}

		$this->folderCache->set($userId, $parentId, new FolderListData($flatRows, $chatIdsByFolder, $sourceCodesByFolder));

		foreach ($resolved as $folderId => $folder)
		{
			if ($folder instanceof PersonalFolder)
			{
				$folder->setChatIds($chatIdsByFolder[$folderId] ?? []);
			}
			else
			{
				$this->hydrateComposition($folder, $sourceCodesByFolder[$folderId] ?? []);
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
			else
			{
				$this->hydrateComposition($folder, $data->sourceCodes[$folderId] ?? []);
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
		$compositeIds = [];
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
			elseif ($this->isCompositeFolder($folder))
			{
				$compositeIds[] = $folderId;
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

		if (!empty($compositeIds))
		{
			$sourceCodesByFolder = $this->loadSourceCodesForFolders($compositeIds);
			foreach ($compositeIds as $folderId)
			{
				$this->hydrateComposition($result[$folderId], $sourceCodesByFolder[$folderId] ?? []);
			}
		}

		return $result;
	}

	/**
	 * A composite folder keeps the codes of its member folders in b_im_folder_source, so its
	 * composition has to be preloaded. Compositeness is read from the definition type rather
	 * than from a concrete class: the definition is a value object and building it does not
	 * touch the stored composition, so the check stays free of extra queries.
	 */
	private function isCompositeFolder(Folder $folder): bool
	{
		return $folder->getDefinition() instanceof CompositeDefinition;
	}

	/**
	 * Applies the stored member codes to a composite folder. The setter belongs to the concrete
	 * folder, so this is the single place where a composite folder is wired to its composition;
	 * a folder that carries none is left untouched. Today "All" is the only composite folder.
	 *
	 * @param string[] $sourceCodes member folder codes
	 */
	private function hydrateComposition(Folder $folder, array $sourceCodes): void
	{
		if ($folder instanceof AllFolder)
		{
			$folder->setSourceCodes($sourceCodes);
		}
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
	 * @return string[]
	 */
	private function loadSourceCodesForFolder(int $folderId): array
	{
		if ($folderId <= 0)
		{
			return [];
		}

		return $this->loadSourceCodesForFolders([$folderId])[$folderId] ?? [];
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

	/**
	 * @param int[] $folderIds
	 * @return array<int, string[]> map folderId -> source folder codes
	 */
	private function loadSourceCodesForFolders(array $folderIds): array
	{
		$result = [];

		if (empty($folderIds))
		{
			return $result;
		}

		$rows = FolderSourceTable::query()
			->setSelect(['FOLDER_ID', 'SOURCE_FOLDER_CODE'])
			->whereIn('FOLDER_ID', $folderIds)
			->fetchAll()
		;

		foreach ($rows as $row)
		{
			$folderId = (int)$row['FOLDER_ID'];
			$result[$folderId][] = (string)$row['SOURCE_FOLDER_CODE'];
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
