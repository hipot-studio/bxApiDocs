<?php

namespace Bitrix\Mail\Helper;

use Bitrix\Mail\Internal\Service\SourceGeneration\GenerationScope;
use Bitrix\Mail\Internals\MailboxDirectoryStorage;
use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Mail\Internals\Entity\MailboxDirectory as MailboxDirectoryEntity;
use Bitrix\Mail\MailboxDirectory;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Text\Emoji;

class MailboxDirectoryHelper
{
	private int $mailboxId;
	private ?int $userId;
	private GenerationScope $generationScope;
	private $storage = null;
	/** @var  ErrorCollection */
	private $errors = [];
	private ?DirSortingHelper $sortingHelper = null;
	private ?array $treeDirsCache = null;

	public function __construct($mailboxId, ?int $userId = null, ?GenerationScope $generationScope = null)
	{
		$this->mailboxId = (int)$mailboxId;
		$this->userId = $userId;
		// A caller that names no scope gets the folders serving the mailbox, prepared ones aside
		$this->generationScope = $generationScope ?? GenerationScope::servingMailbox($this->mailboxId);
		$this->storage = new MailboxDirectoryStorage($mailboxId, $this->generationScope);
		$this->errors = new ErrorCollection();
	}

	public function getGenerationScope(): GenerationScope
	{
		return $this->generationScope;
	}

	public function getDirs()
	{
		return $this->storage->get('all', []);
	}

	public function setDirs(array $dirs)
	{
		$this->storage->set($dirs);
		$this->treeDirsCache = null;
	}

	public function reloadDirs()
	{
		$this->storage->init();
		$this->treeDirsCache = null;
	}

	public function getErrors()
	{
		return $this->errors;
	}

	public function getDrafts()
	{
		$list = $this->storage->get('draft', []);

		return reset($list);
	}

	public function getIncome()
	{
		$list = $this->storage->get('income', []);

		return reset($list);
	}

	public function getOutcome()
	{
		$list = $this->storage->get('outcome', []);

		return reset($list);
	}

	public function getSpam()
	{
		$list = $this->storage->get('spam', []);

		return reset($list);
	}

	public function getTrash()
	{
		$list = $this->storage->get('trash', []);

		return reset($list);
	}

	public function getIncomePath($emojiEncode = false)
	{
		$dir = $this->getIncome();

		if ($dir != null)
		{
			return $dir->getPath($emojiEncode);
		}

		return null;
	}

	public function getOutcomePath($emojiEncode = false)
	{
		$dir = $this->getOutcome();

		if ($dir != null)
		{
			return $dir->getPath($emojiEncode);
		}

		return null;
	}

	public function getDraftsPath($emojiEncode = false)
	{
		$dir = $this->getDrafts();

		if ($dir != null)
		{
			return $dir->getPath($emojiEncode);
		}

		return null;
	}

	public function getSpamPath($emojiEncode = false)
	{
		$dir = $this->getSpam();

		if ($dir != null)
		{
			return $dir->getPath($emojiEncode);
		}

		return null;
	}

	public function getTrashPath($emojiEncode = false)
	{
		$dir = $this->getTrash();

		if ($dir != null)
		{
			return $dir->getPath($emojiEncode);
		}

		return null;
	}

	public function getDirPathByHash($hash)
	{
		$dir = $this->storage->getByHash($hash);

		if ($dir != null)
		{
			return $dir->getPath();
		}

		return null;
	}

	public function getDirByHash($hash)
	{
		if (!$hash)
		{
			return null;
		}

		$dir = $this->storage->getByHash($hash);

		if ($dir != null)
		{
			return $dir;
		}

		return null;
	}

	public function getDirPathByType($type)
	{
		switch ($type)
		{
			case MailboxDirectoryTable::TYPE_INCOME:
				return $this->getIncomePath();

				break;
			case MailboxDirectoryTable::TYPE_OUTCOME:
				return $this->getOutcomePath();

				break;
			case MailboxDirectoryTable::TYPE_SPAM:
				return $this->getSpamPath();

				break;
			case MailboxDirectoryTable::TYPE_TRASH:
				return $this->getTrashPath();

				break;
			default:
				return '';
		}
	}

	public function getDirByPath(?string $path)
	{
		if (!$path)
		{
			return null;
		}

		$dir = $this->storage->getByPath($path);

		if ($dir != null)
		{
			return $dir;
		}

		return null;
	}

	public static function hasChildren(string $flags): bool
	{
		$hasChild = (bool)preg_match('/(\x5cHasChildren)/ix', $flags);

		if (!$hasChild)
		{
			$hasNoChildren = (bool)preg_match('/(\x5cHasNoChildren)/ix', $flags);
			$noInferiors = (bool)preg_match('/(\x5cNoinferiors)/ix', $flags);

			if (!$hasNoChildren && !$noInferiors)
			{
				$hasChild = true;
			}
		}

		return $hasChild;
	}

	/**
	 * @return MailboxDirectoryEntity[]
	 */
	public function getSyncDirs(): array
	{
		return array_filter($this->getDirs(), static fn ($item) => $item->isSync());
	}

	/**
	 * @return string[]
	 */
	public function getSyncDirsMd5(): array
	{
		$syncDirs = $this->getSyncDirs();
		$filteredDirs = array_filter($syncDirs, static fn ($dir) => !$dir->isInvisibleToCounters());

		return array_values(array_map(static fn ($dir) => $dir->getDirMd5(), $filteredDirs));
	}

	/**
	 * Aggregates counter-visible sync dirs MD5 across the given mailboxes.
	 *
	 * @param int[] $mailboxIds
	 * @return string[]
	 */
	public static function getSyncDirsMd5ForMailboxes(array $mailboxIds): array
	{
		$result = [];
		foreach ($mailboxIds as $mailboxId)
		{
			$helper = Mailbox::createInstance((int)$mailboxId, false);
			if ($helper instanceof Mailbox)
			{
				$result = array_merge($result, $helper->getDirsHelper()->getSyncDirsMd5());
			}
		}

		return array_values(array_unique($result));
	}

	/**
	 * Aggregates Outcome (Sent) dirs MD5 across the given mailboxes.
	 *
	 * @param int[] $mailboxIds
	 * @return string[]
	 */
	public static function getOutcomeDirsMd5ForMailboxes(array $mailboxIds): array
	{
		$result = [];
		foreach ($mailboxIds as $mailboxId)
		{
			$helper = Mailbox::createInstance($mailboxId, false);
			if (!$helper instanceof Mailbox)
			{
				continue;
			}

			$outcome = $helper->getDirsHelper()->getOutcome();
			if ($outcome)
			{
				$result[] = $outcome->getDirMd5();
			}
		}

		return array_values(array_unique($result));
	}

	/**
	 * @param int[] $mailboxIds
	 * @return string[]
	 */
	public static function getSpamAndTrashDirsMd5ForMailboxes(array $mailboxIds): array
	{
		$result = [];
		foreach ($mailboxIds as $mailboxId)
		{
			$helper = Mailbox::createInstance((int)$mailboxId, false);
			if (!$helper instanceof Mailbox)
			{
				continue;
			}

			$dirsHelper = $helper->getDirsHelper();
			foreach ([$dirsHelper->getSpam(), $dirsHelper->getTrash()] as $dir)
			{
				if ($dir)
				{
					$result[] = $dir->getDirMd5();
				}
			}
		}

		return array_values(array_unique($result));
	}

	/**
	 * @return MailboxDirectoryEntity[]
	 * @throws \Exception
	 */
	public function getSyncDirsOrdered(): array
	{
		$syncDirs = $this->getSyncDirs();

		return $this->order($syncDirs);
	}

	/**
	 * @param ?string $excludeDirPath
	 * @return MailboxDirectoryEntity[]
	 * @throws \Exception
	 */
	public function getSyncDirsOrderByTime(?string $excludeDirPath = null): array
	{
		return array_filter(
			$this->orderByTime($this->getSyncDirs()),
			static fn ($item) => $item->getPath() !== $excludeDirPath,
		);
	}

	public function getSyncDirsPath($emojiEncode = false)
	{
		$list = [];

		foreach ($this->getDirs() as $item)
		{
			if ($item->isSync())
			{
				$list[] = $item->getPath($emojiEncode);
			}
		}

		return $list;
	}

	public function getAllOneLevel()
	{
		$list = [];

		foreach ($this->getDirs() as $item)
		{
			if ((int)$item->getLevel() === 1)
			{
				$list[$item->getPath()] = $item;
			}
		}

		return $list;
	}

	/**
	 * @throws \Exception
	 */
	public function buildDirectoryTreeForContextMenu(int $mailboxId, Mailbox $mailboxHelper)
	{
		$directoriesWithNumberOfUnreadMessages = $mailboxHelper->getDirsMd5WithCounter($mailboxId);
		static $directoryTreeForContextMenu;

		if (!is_null($directoryTreeForContextMenu))
		{
			return $directoryTreeForContextMenu;
		}

		$systemDirs = [
			'default' => $this->getDefaultDir(),
			'spam' => $this->getSpam(),
			'trash' => $this->getTrash(),
			'outcome' => $this->getOutcome(),
			'drafts' => $this->getDrafts(),
		];

		$systemDirMap = [];
		foreach ($systemDirs as $type => $dirObj)
		{
			if ($dirObj && method_exists($dirObj, 'getId'))
			{
				$systemDirMap[$dirObj->getId()] = $type;
			}
		}

		$flat = [];
		$list = [];
		$syncDirIds = [];
		$dirs = $this->getSyncDirsOrdered();
		foreach ($dirs as $dir)
		{
			$syncDirIds[$dir->getId()] = true;
		}

		foreach ($dirs as $dir)
		{
			$id = $dir->getId();

			if ($dir->isVirtualFolder())
			{
				continue;
			}

			$folderData = $this->buildFolderData(
				$dir,
				$systemDirMap,
				$directoriesWithNumberOfUnreadMessages,
			);

			$flat[$id] = $folderData;
			if (!empty($flat[$dir->getParentId()]))
			{
				foreach ($flat[$dir->getParentId()]['items'] as $k => $item)
				{
					if (!empty($item['id']) && $item['id'] === 'loading')
					{
						array_splice($flat[$dir->getParentId()]['items'], $k, 1);
					}
				}

				$flat[$dir->getParentId()]['items'][] = &$flat[$dir->getId()];
			}
			else
			{
				$list[] = &$flat[$dir->getId()];
			}
		}

		foreach ($systemDirs as $type => $dirObj)
		{
			if (!$dirObj)
			{
				continue;
			}

			$id = $dirObj->getId();
			if (!isset($syncDirIds[$id]))
			{
				$folderData = $this->buildFolderData(
					$dirObj,
					$systemDirMap,
					$directoriesWithNumberOfUnreadMessages,
					true,
				);
				$list[] = $folderData;
			}
		}

		$directoryTreeForContextMenu = $list;

		return $list;
	}

	/**
	 * @throws \Exception
	 */
	private function buildFolderData(
		$dir,
		array $systemDirMap,
		array $directoriesWithNumberOfUnreadMessages,
		bool $isHidden = false,
	): array
	{
		$id = $dir->getId();
		$path = $dir->getPath(true);
		$isCounted = !(($dir->isTrash() || $dir->isSpam()));
		$hasChild = self::hasChildren((string)$dir->getFlags());

		return [
			'id' => $id,
			'path' => $path,
			'order' => $this->getOrder($dir),
			'delimiter' => $dir->getDelimiter(),
			'name' => htmlspecialcharsbx($dir->getName()),
			'type' => $systemDirMap[$id] ?? 'custom',
			'isHidden' => $isHidden,
			'dataset' => [
				'path' => $path,
				'dirMd5' => $dir->getDirMd5(),
				'isDisabled' => $dir->isDisabled(),
				'hasChild' => $hasChild,
				'isCounted' => $isCounted,
			],
			'count' => isset($directoriesWithNumberOfUnreadMessages[$dir->getDirMd5()]['MESSAGE_COUNT'])
				? (int)$directoriesWithNumberOfUnreadMessages[$dir->getDirMd5()]['MESSAGE_COUNT']
				: 0
			,
			'unseen' => isset($directoriesWithNumberOfUnreadMessages[$dir->getDirMd5()]['UNSEEN'])
				? (int)$directoriesWithNumberOfUnreadMessages[$dir->getDirMd5()]['UNSEEN']
				: 0
			,
		];
	}

	/**
	 * @deprecated Use \Bitrix\Mail\Helper\MailboxDirectoryHelper::getOrder
	 * @throws \Exception
	 */
	public function getOrderByDefault(MailboxDirectoryEntity $dir): int
	{
		return $this->getOrder($dir);
	}

	/**
	 * Get sorting weight for a directory.
	 *
	 * @throws \Exception
	 */
	public function getOrder(MailboxDirectoryEntity $dir): int
	{
		return $this->getSortingHelper()->getWeight($dir);
	}

	/**
	 * @param MailboxDirectoryEntity[] $dirs
	 * @param string $strategy
	 * @return MailboxDirectoryEntity[]
	 *
	 * @throws \Exception
	 */
	public function order(array $dirs, string $strategy = DirSortingHelper::STRATEGY_PRESET): array
	{
		return $this->getSortingHelper()->order($dirs, $strategy);
	}

	private function getSortingHelper(): DirSortingHelper
	{
		if ($this->sortingHelper === null)
		{
			$this->sortingHelper = new DirSortingHelper(
				$this->mailboxId,
				$this->getProviderCode(),
				$this->userId,
			);
		}

		return $this->sortingHelper;
	}

	/**
	 * @throws \Exception
	 */
	private function getProviderCode(): string
	{
		$mailboxHelper = Mailbox::createInstance($this->mailboxId);

		return $mailboxHelper ? $mailboxHelper->getProviderCode() : 'default';
	}

	private function orderByName($dirs)
	{
		usort($dirs, function ($a, $b)
		{
			$aSort = $a->getName();
			$bSort = $b->getName();

			if (
				$a->isSpam()
				|| $a->isTrash()
				|| $a->isDraft()
				|| $a->isOutcome()
			)
			{
				$aSort = 1000;
			}

			if (
				$b->isSpam()
				|| $b->isTrash()
				|| $b->isDraft()
				|| $b->isOutcome()
			)
			{
				$bSort = 1000;
			}

			if ($aSort === $bSort)
			{
				return 0;
			}

			return $aSort > $bSort ? 1 : -1;
		});

		return $dirs;
	}

	/**
	 * @param MailboxDirectoryEntity[] $dirs
	 * @return MailboxDirectoryEntity[]
	 * @throws \Exception
	 */
	private function orderByTime(array $dirs): array
	{
		usort($dirs, function ($a, $b)
		{
			$aSort = $a->getSyncTime() ?: ($this->getOrder($a));
			$bSort = $b->getSyncTime() ?: ($this->getOrder($b));

			return $aSort <=> $bSort;
		});

		return $dirs;
	}

	/**
	 * @throws \Exception
	 */
	public function getLastSyncDirOrdered(?string $excludeDirPath = null): MailboxDirectoryEntity
	{
		$syncDirs = $this->getSyncDirs();
		$list = $this->order($syncDirs);
		$list = array_filter($list, static fn ($item) => $item->getPath() !== $excludeDirPath);

		return end($list);
	}

	public function getCurrentSyncDirByTime()
	{
		$list = $this->orderByTime($this->getSyncDirs());

		return reset($list);
	}

	/**
	 * @throws \Exception
	 */
	public function getCurrentSyncDirPositionOrdered(string $path, ?string $excludeDirPath = null): int
	{
		$list = $this->getSyncDirsOrdered();
		$list = array_filter($list, static fn ($item) => $item->getPath() !== $excludeDirPath);

		foreach ($list as $index => $item)
		{
			if ($item->getPath() === $path)
			{
				return $index;
			}
		}

		return -1;
	}

	public function removeDirsLikePath(array $dirs)
	{
		$removeRows = [];

		foreach ($dirs as $item)
		{
			$removeRows[] = [
				'=PATH' => $item->getPath(true),
			];
			//deleting subfolders
			$removeRows[] = [
				'%=PATH' => $item->getPath(true) . $item->getDelimiter() . '%',
			];
		}

		if (!empty($removeRows))
		{
			$removeRows = array_merge(['LOGIC' => 'OR'], $removeRows);

			// The subtree removal stays inside its own generation: G2 never deletes G1
			$filter = array_merge($this->generationScope->apply([
				'LOGIC'       => 'AND',
				'=MAILBOX_ID' => $this->mailboxId,
			]), [$removeRows]);

			MailboxDirectory::deleteList($filter);
		}
	}

	public function getDefaultDir()
	{
		$inboxDir = $this->getIncome();
		$sendDir = $this->getOutcome();
		$dirs = $this->getDirs();

		foreach ([$inboxDir, $sendDir] as $dir)
		{
			if ($dir != null && !$dir->isDisabled() && $dir->isSync())
			{
				return $dir;
			}
		}

		foreach ($dirs as $dir)
		{
			if (!$dir->isDisabled() && $dir->isSync())
			{
				return $dir;
			}
		}

		return '';
	}

	public function getDefaultDirPath($emojiEncode = false)
	{
		$dir = $this->getDefaultDir();

		if($dir !== '')
		{
			return $dir->getPath($emojiEncode);
		}

		return '';
	}

	/**
	 * @return MailboxDirectoryEntity[]
	 * @throws \Exception
	 */
	public function buildTreeDirs(): array
	{
		if ($this->treeDirsCache !== null)
		{
			return $this->treeDirsCache;
		}

		$list = [];
		$result = [];
		$dirs = $this->getDirs();

		foreach ($dirs as $dir)
		{
			$list[$dir->getId()] = $dir;
		}

		foreach ($list as $id => $dir)
		{
			if (!empty($list[$dir->getParentId()]))
			{
				$list[$dir->getParentId()]->addChild($dir);
			}
			else
			{
				$result[$dir->getId()] = $dir;
			}
		}

		$this->treeDirsCache = $this->order($result);

		return $this->treeDirsCache;
	}

	/**
	 * Lists one level below the folder over a live connection and stores it in the
	 * generation of this helper.
	 *
	 * @param Mailbox|null $engine The connection to list over. Only the active generation
	 *        is projected into the connection fields of the mailbox, so a helper working
	 *        in another generation cannot build its engine from there and receives it.
	 */
	public function syncChildren($parent, ?Mailbox $engine = null)
	{
		$pattern = sprintf('%s%s%%', $parent->getPath(), $parent->getDelimiter());
		$mailboxHelper = $engine ?? Mailbox::createInstance($this->mailboxId);
		$dirs = $mailboxHelper->listDirs($pattern);

		if ($dirs === false)
		{
			$this->errors = $mailboxHelper->getErrors();

			return false;
		}

		$dbDirs = $this->getOneLevelByParentId($parent);

		$params = [
			'level'     => $parent->getLevel() + 1,
			'parent_id' => $parent->getId(),
			'root_id'   => $parent->getRootId() ?: $parent->getId(),
		];

		if (!$parent->isVirtualFolder())
		{
			$params['is_sync'] = MailboxDirectoryTable::INACTIVE;
		}

		$dirs = array_map(function ($item) use ($params)
		{
			return array_merge($item, $params);
		}, $dirs);

		$this->addSyncDirs($dirs, $dbDirs);

		if (!empty($dbDirs))
		{
			$this->updateSyncDirs($dirs, $dbDirs);
			$this->removeSyncDirs($dirs, $dbDirs);
		}

		return true;
	}

	public function getOneLevelByParentId($parent)
	{
		return MailboxDirectory::fetchOneLevelByParentId(
			$this->mailboxId,
			$parent->getId(),
			$parent->getLevel() + 1,
			$this->generationScope,
		);
	}

	public function getAllLevelByParentId($parent)
	{
		return MailboxDirectory::fetchAllLevelByParentId(
			$this->mailboxId,
			$parent->getPath(true) . $parent->getDelimiter() . '%',
			$parent->getLevel() + 1,
			$this->generationScope,
		);
	}

	public function addSyncDirs($dirs, $dbDirs)
	{
		$diffDirs = array_diff_key($dirs, $dbDirs);

		$addRows = array_map(
			function ($dir)
			{
				if (!isset($dir['is_sync']))
				{
					$dir['is_sync'] = !preg_grep('/^ \x5c ( Drafts | Trash | Junk | Spam ) $/ix', $dir['flags']);
				}

				return [
					'MAILBOX_ID'  => $this->mailboxId,
					// A new folder always belongs to the generation this helper works in
					'GENERATION_ID' => $this->generationScope->getStampGenerationId(),
					'NAME'        => Emoji::encode($dir['name']),
					'PATH'        => Emoji::encode($dir['path']),
					'LEVEL'       => $dir['level'] ?? 1,
					'PARENT_ID'   => $dir['parent_id'] ?? null,
					'ROOT_ID'     => $dir['root_id'] ?? null,
					'FLAGS'       => MailboxDirectoryHelper::getFlags($dir['flags']),
					'DELIMITER'   => $dir['delim'],
					'DIR_MD5'     => md5(Emoji::encode($dir['path'])),
					'IS_SYNC'     => $dir['is_sync'],
					'IS_INCOME'   => (int)($dir['level'] ?? 1) === 1 && mb_strtoupper($dir['name']) === 'INBOX',
					'IS_OUTCOME'  => preg_grep('/^ \x5c Sent $/ix', $dir['flags']),
					'IS_DRAFT'    => preg_grep('/^ \x5c Drafts $/ix', $dir['flags']),
					'IS_TRASH'    => preg_grep('/^ \x5c Trash $/ix', $dir['flags']),
					'IS_SPAM'     => preg_grep('/^ \x5c ( Junk | Spam ) $/ix', $dir['flags']),
					'IS_DISABLED' => preg_grep('/^ \x5c Noselect $/ix', $dir['flags']),
				];
			},
			$diffDirs,
		);

		if (!empty($addRows))
		{
			MailboxDirectory::addMulti($addRows, true);
		}
	}

	public function updateSyncDirs($dirs, $dbDirs)
	{
		$updateRows = array_udiff_assoc($dirs, $dbDirs, function ($a, $b)
		{
			$flagsA = MailboxDirectoryHelper::getFlags($a['flags']);
			$flagsB = $b->getFlags();

			$delimA = $a['delim'];
			$delimB = $b->getDelimiter();

			if ($flagsA !== $flagsB)
			{
				return $flagsA > $flagsB ? 1 : -1;
			}
			if ($delimA !== $delimB)
			{
				return $delimA > $delimB ? 1 : -1;
			}

			return 0;
		});

		foreach ($updateRows as $row)
		{
			$dbDir = $this->getDirByPath(Emoji::encode($row['path']));

			if (!$dbDir)
			{
				continue;
			}

			MailboxDirectory::update(
				$dbDir->getId(),
				[
					'DELIMITER' => $row['delim'],
					'FLAGS' => self::getFlags($row['flags']),
				],
			);
		}
	}

	public function removeSyncDirs($dirs, $dbDirs)
	{
		$diffDirs = array_diff_key($dbDirs, $dirs);

		if (!empty($diffDirs))
		{
			$this->removeDirsLikePath($diffDirs);
		}
	}

	public function updateCache(): void
	{
		$mailboxId = (int)$this->mailboxId;
		if ($mailboxId > 0)
		{
			MailboxDirectory::invalidateCache($mailboxId, $this->generationScope->getStampGenerationId());
			MailboxDirectory::fetchAll($mailboxId, $this->generationScope);
		}
	}

	public function toggleSyncDirs($dirs, bool $activateSync = true)
	{
		$enableRows = [];
		$disableRows = [];

		foreach ($dirs as $dir)
		{
			$hash = $dir['dirMd5'] ?? null;
			$value = isset($dir['value']) ? intval($dir['value']) : 0;

			if (!$hash || !in_array($value, [MailboxDirectoryTable::ACTIVE, MailboxDirectoryTable::INACTIVE]))
			{
				continue;
			}

			if ($value === MailboxDirectoryTable::ACTIVE)
			{
				$enableRows[] = $hash;
			}
			else
			{
				$disableRows[] = $hash;
			}
		}

		if (!empty($enableRows))
		{
			MailboxDirectory::updateSyncDirs($enableRows, MailboxDirectoryTable::ACTIVE, $this->mailboxId, $this->generationScope);
		}

		if (!empty($disableRows))
		{
			MailboxDirectory::updateSyncDirs($disableRows, MailboxDirectoryTable::INACTIVE, $this->mailboxId, $this->generationScope);
		}

		if ($activateSync)
		{
			$mailboxHelper = Mailbox::createInstance($this->mailboxId);
			$mailboxHelper->activateSync();
		}
	}

	public function saveDirsTypes($dirs)
	{
		foreach ($dirs as $dir)
		{
			$type = !empty($dir['type']) ? $dir['type'] : null;
			$hash = !empty($dir['dirMd5']) ? $dir['dirMd5'] : null;

			if (!self::isDirsTypes($type) || !$hash)
			{
				continue;
			}

			$result = MailboxDirectory::fetchOneByMailboxIdAndHash($this->mailboxId, $hash, $this->generationScope);

			if ($result != null)
			{
				MailboxDirectory::resetDirsTypes($this->mailboxId, $type, $this->generationScope);

				MailboxDirectory::update(
					$result->getId(),
					[
						$type => MailboxDirectoryTable::ACTIVE,
					],
				);
			}
		}
	}

	public function syncDbDirs($dirs)
	{
		$dbDirs = $this->getAllOneLevel();

		$this->syncDbDirsLevel($dirs, $dbDirs);
	}

	/**
	 * Stores a complete IMAP LIST snapshot without flattening its hierarchy.
	 *
	 * @param array<int, array<string, mixed>> $dirs
	 */
	public function syncDbDirsSnapshot(array $dirs): void
	{
		$snapshot = $this->normalizeDbDirsSnapshot($dirs);
		$directoriesByLevel = [];
		$storedDirectories = $this->getDirs();

		foreach ($snapshot as $path => $dir)
		{
			$directoriesByLevel[$dir['level']][$path] = $dir;
		}
		ksort($directoriesByLevel);

		foreach ($directoriesByLevel as $level => $directories)
		{
			foreach ($directories as $path => $directory)
			{
				$parentPath = $directory['parent_path'];
				if ($parentPath === null)
				{
					continue;
				}

				$parent = $storedDirectories[$parentPath] ?? null;
				if ($parent === null)
				{
					throw new \RuntimeException(sprintf('The parent IMAP directory %s was not saved', $parentPath));
				}

				$directories[$path] += [
					'parent_id' => $parent->getId(),
					'root_id' => $parent->getRootId() ?: $parent->getId(),
				];
			}

			$storedAtLevel = [];
			foreach ($storedDirectories as $stored)
			{
				if ((int)$stored->getLevel() === $level)
				{
					$storedAtLevel[$stored->getPath()] = $stored;
				}
			}

			$this->syncDbDirsLevel($directories, $storedAtLevel, false);
			foreach (array_keys($storedAtLevel) as $path)
			{
				unset($storedDirectories[$path]);
			}
			foreach ($this->fetchDbDirsAtLevel($level) as $path => $stored)
			{
				$storedDirectories[$path] = $stored;
			}
			$this->setDirs($storedDirectories);
		}

		$this->updateCache();
		$this->assertDbDirsSnapshot($snapshot);
	}

	/**
	 * Adds folders to the stored ones of the scope and touches nothing else: a path already stored is
	 * skipped, and no stored folder is updated or removed. The parent of a folder is either stored or
	 * among the added ones.
	 *
	 * @param array<int, array<string, mixed>> $dirs In the shape of {@see syncDbDirsSnapshot()}.
	 */
	public function appendDbDirsSnapshot(array $dirs): void
	{
		$this->updateCache();
		$this->reloadDirs();
		$storedDirectories = $this->getDirs();
		$added = array_diff_key($this->normalizeDbDirsSnapshot($dirs, $storedDirectories), $storedDirectories);
		if ($added === [])
		{
			return;
		}

		$directoriesByLevel = [];
		foreach ($added as $path => $dir)
		{
			$directoriesByLevel[$dir['level']][$path] = $dir;
		}
		ksort($directoriesByLevel);

		foreach ($directoriesByLevel as $level => $directories)
		{
			foreach ($directories as $path => $directory)
			{
				$parentPath = $directory['parent_path'];
				if ($parentPath === null)
				{
					continue;
				}

				$parent = $storedDirectories[$parentPath] ?? null;
				if ($parent === null)
				{
					throw new \RuntimeException(sprintf('The parent IMAP directory %s was not saved', $parentPath));
				}

				$directories[$path] += [
					'parent_id' => $parent->getId(),
					'root_id' => $parent->getRootId() ?: $parent->getId(),
				];
			}

			$this->addSyncDirs($directories, $storedDirectories);
			foreach ($this->fetchDbDirsAtLevel($level) as $path => $stored)
			{
				$storedDirectories[$path] = $stored;
			}
		}

		$this->updateCache();
		$this->reloadDirs();
		$stored = $this->getDirs();
		foreach ($added as $path => $expected)
		{
			$directory = $stored[$path] ?? null;
			$parent = $expected['parent_path'] === null ? null : ($stored[$expected['parent_path']] ?? null);
			if (
				$directory === null
				|| (int)$directory->getLevel() !== $expected['level']
				|| (int)$directory->getParentId() !== ($parent?->getId() ?? 0)
			)
			{
				throw new \RuntimeException(sprintf('The added IMAP directory %s was not saved', $path));
			}
		}
	}

	private function syncDbDirsLevel(array $dirs, array $dbDirs, bool $refreshCache = true): void
	{
		$this->addSyncDirs($dirs, $dbDirs);
		$this->updateSyncDirs($dirs, $dbDirs);
		$this->removeSyncDirs($dirs, $dbDirs);

		if ($refreshCache)
		{
			$this->updateCache();
		}
	}

	/** @return array<string, MailboxDirectoryEntity> */
	private function fetchDbDirsAtLevel(int $level): array
	{
		$rows = MailboxDirectoryTable::getList([
			'filter' => $this->generationScope->apply([
				'=MAILBOX_ID' => $this->mailboxId,
				'=LEVEL' => $level,
			]),
			'order' => ['ID' => 'ASC'],
		]);
		$directories = [];
		while ($directory = $rows->fetchObject())
		{
			$directories[$directory->getPath()] = $directory;
		}

		return $directories;
	}

	/**
	 * @param array<int, array<string, mixed>> $dirs
	 * @param array<string, mixed> $storedDirectories Stored folders by path, which a parent may be one of.
	 * @return array<string, array<string, mixed>>
	 */
	private function normalizeDbDirsSnapshot(array $dirs, array $storedDirectories = []): array
	{
		$snapshot = [];

		foreach ($dirs as $dir)
		{
			$path = (string)($dir['name'] ?? '');
			$delimiter = (string)($dir['delim'] ?? '');
			$parts = $dir['path'] ?? null;

			if ($path === '' || !is_array($parts) || $parts === [])
			{
				throw new \UnexpectedValueException('An IMAP directory snapshot has no full path');
			}

			$parts = array_map('strval', array_values($parts));
			if (in_array('', $parts, true))
			{
				throw new \UnexpectedValueException(sprintf('The IMAP directory %s has an empty path segment', $path));
			}
			if (count($parts) > 1 && ($delimiter === '' || mb_strtoupper($delimiter) === 'NIL'))
			{
				throw new \UnexpectedValueException(sprintf('The IMAP directory %s has no hierarchy delimiter', $path));
			}
			if (implode($delimiter, $parts) !== $path)
			{
				throw new \UnexpectedValueException(sprintf('The IMAP directory path %s is inconsistent', $path));
			}
			if (isset($snapshot[$path]))
			{
				throw new \UnexpectedValueException(sprintf('The IMAP directory %s is listed more than once', $path));
			}

			$level = count($parts);
			$parentPath = $level > 1 ? implode($delimiter, array_slice($parts, 0, -1)) : null;
			$dir['name'] = (string)end($parts);
			$dir['path'] = $path;
			$dir['level'] = $level;
			$dir['parent_path'] = $parentPath;
			$snapshot[$path] = $dir;
		}

		foreach ($snapshot as $path => $dir)
		{
			$parentPath = $dir['parent_path'];
			if ($parentPath !== null && !isset($snapshot[$parentPath]) && !isset($storedDirectories[$parentPath]))
			{
				throw new \UnexpectedValueException(sprintf(
					'The parent IMAP directory %s of %s is missing from the snapshot',
					$parentPath,
					$path,
				));
			}
		}

		return $snapshot;
	}

	/**
	 * @param array<string, array<string, mixed>> $snapshot
	 */
	private function assertDbDirsSnapshot(array $snapshot): void
	{
		$this->reloadDirs();
		$stored = $this->getDirs();
		$storedPaths = array_keys($stored);
		$snapshotPaths = array_keys($snapshot);
		sort($storedPaths);
		sort($snapshotPaths);

		if ($storedPaths !== $snapshotPaths)
		{
			throw new \RuntimeException('The complete IMAP directory snapshot was not saved');
		}

		foreach ($snapshot as $path => $expected)
		{
			$directory = $stored[$path] ?? null;
			$parentPath = $expected['parent_path'];
			$parent = $parentPath === null ? null : ($stored[$parentPath] ?? null);
			$expectedParentId = $parent?->getId() ?? 0;
			$expectedRootId = $parent === null ? 0 : ($parent->getRootId() ?: $parent->getId());

			if (
				$directory === null
				|| $directory->getDelimiter() !== $expected['delim']
				|| (int)$directory->getLevel() !== $expected['level']
				|| (int)$directory->getParentId() !== $expectedParentId
				|| (int)$directory->getRootId() !== $expectedRootId
			)
			{
				throw new \RuntimeException(sprintf(
					'The topology of IMAP directory %s was not saved: expected %s, got %s',
					$path,
					json_encode([
						'delimiter' => $expected['delim'],
						'level' => $expected['level'],
						'parentId' => $expectedParentId,
						'rootId' => $expectedRootId,
					]),
					json_encode($directory === null ? null : [
						'delimiter' => $directory->getDelimiter(),
						'level' => $directory->getLevel(),
						'parentId' => $directory->getParentId(),
						'rootId' => $directory->getRootId(),
					]),
				));
			}
		}
	}

	public function updateMessageCount($id, $count)
	{
		MailboxDirectory::updateMessageCount($id, $count);
	}

	public static function isDirsTypes($name)
	{
		if (in_array(
			$name,
			[
				MailboxDirectoryTable::TYPE_OUTCOME,
				MailboxDirectoryTable::TYPE_TRASH,
				MailboxDirectoryTable::TYPE_SPAM,
			],
			true,
		))
		{
			return true;
		}

		return false;
	}

	public static function getFlags(array $flags)
	{
		sort($flags);

		return implode(' ', $flags);
	}

	public static function getMaxLevelDirs()
	{
		return (int)\Bitrix\Main\Config\Option::get('mail', 'maxLevelDirs', 20);
	}

	public static function setMaxLevelDirs(int $val)
	{
		\Bitrix\Main\Config\Option::set('mail', 'maxLevelDirs', $val);
	}

	public static function getCurrentSyncDir()
	{
		return \Bitrix\Main\Config\Option::get('mail', 'currentSyncDir', '');
	}

	public static function setCurrentSyncDir(string $path)
	{
		\Bitrix\Main\Config\Option::set('mail', 'currentSyncDir', $path);
	}
}
