<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper;

use Bitrix\Mail\Helper\Dto\DirSortingData;
use Bitrix\Mail\Internals\Entity\MailboxDirectory;
use Bitrix\Mail\Internals\MailboxDirectoryTable;

class DirSortingHelper
{
	public const STRATEGY_ALPHA = 'alpha';
	public const STRATEGY_PRESET = 'preset';

	public const DIR_TYPE_OTHER = 'other';

	private const DEFAULT_BASE_WEIGHT = 1000;
	private const GROUP_MULTIPLIER = 10000;

	private static array $presets = [
		'gmail' => [
			MailboxDirectoryTable::TYPE_INCOME => 10,
			self::DIR_TYPE_OTHER => 20,
			MailboxDirectoryTable::TYPE_OUTCOME => 30,
			MailboxDirectoryTable::TYPE_DRAFT => 40,
			MailboxDirectoryTable::TYPE_SPAM => 50,
			MailboxDirectoryTable::TYPE_TRASH => 60,
		],
		'outlook.com' => [
			MailboxDirectoryTable::TYPE_INCOME => 10,
			MailboxDirectoryTable::TYPE_SPAM => 20,
			MailboxDirectoryTable::TYPE_DRAFT => 30,
			MailboxDirectoryTable::TYPE_OUTCOME => 40,
			MailboxDirectoryTable::TYPE_TRASH => 50,
		],
		'office365' => [
			MailboxDirectoryTable::TYPE_INCOME => 10,
			MailboxDirectoryTable::TYPE_SPAM => 20,
			MailboxDirectoryTable::TYPE_DRAFT => 30,
			MailboxDirectoryTable::TYPE_OUTCOME => 40,
			MailboxDirectoryTable::TYPE_TRASH => 50,
		],
		'exchangeOnline' => [
			MailboxDirectoryTable::TYPE_INCOME => 10,
			MailboxDirectoryTable::TYPE_SPAM => 20,
			MailboxDirectoryTable::TYPE_DRAFT => 30,
			MailboxDirectoryTable::TYPE_OUTCOME => 40,
			MailboxDirectoryTable::TYPE_TRASH => 50,
		],
		'yandex' => [
			MailboxDirectoryTable::TYPE_INCOME => 10,
			self::DIR_TYPE_OTHER => 20,
			MailboxDirectoryTable::TYPE_OUTCOME => 30,
			MailboxDirectoryTable::TYPE_TRASH => 40,
			MailboxDirectoryTable::TYPE_SPAM => 50,
			MailboxDirectoryTable::TYPE_DRAFT => 60,
		],
		'mail.ru' => [
			MailboxDirectoryTable::TYPE_INCOME => 10,
			self::DIR_TYPE_OTHER => 20,
			MailboxDirectoryTable::TYPE_OUTCOME => 30,
			MailboxDirectoryTable::TYPE_DRAFT => 40,
			MailboxDirectoryTable::TYPE_SPAM => 50,
			MailboxDirectoryTable::TYPE_TRASH => 60,
		],
		'default' => [
			MailboxDirectoryTable::TYPE_INCOME => 10,
			MailboxDirectoryTable::TYPE_OUTCOME => 20,
			MailboxDirectoryTable::TYPE_DRAFT => 30,
			MailboxDirectoryTable::TYPE_SPAM => 40,
			MailboxDirectoryTable::TYPE_TRASH => 50,
		],
	];

	private int $mailboxId;
	private string $providerCode;

	public function __construct(int $mailboxId, string $providerCode = 'default')
	{
		$this->mailboxId = $mailboxId;
		$this->providerCode = $providerCode;
	}

	/**
	 * @param MailboxDirectory[] $dirs
	 * @param string $strategy
	 * @return MailboxDirectory[]
	 */
	public function order(array $dirs, string $strategy = self::STRATEGY_PRESET): array
	{
		$sortedDirsByDbData = $this->sortByDbData();

		if (!empty($sortedDirsByDbData))
		{
			return $sortedDirsByDbData;
		}

		return $this->sortByStrategy($dirs, $strategy);
	}

	public function getWeight(MailboxDirectory $dir): int
	{
		$data = $this->extractDirSortingData($dir);

		return $this->calculateWeight($data);
	}

	/**
	 * @param MailboxDirectory[] $dirs
	 * @param string $strategy
	 * @return MailboxDirectory[]
	 */
	public function sortByStrategy(array $dirs, string $strategy = self::STRATEGY_PRESET): array
	{
		if (empty($dirs))
		{
			return [];
		}

		$comparator = $this->getSortingCallback($dirs, $strategy);
		usort($dirs, $comparator);

		return $dirs;
	}

	/**
	 * @param MailboxDirectory[] $dirs
	 * @param string $strategy
	 * @return callable
	 */
	public function getSortingCallback(
		array $dirs = [],
		string $strategy = self::STRATEGY_PRESET,
	): callable
	{
		return match ($strategy)
		{
			self::STRATEGY_ALPHA => $this->getAlphaComparator(),
			default => $this->getPresetComparator($dirs),
		};
	}

	private function getAlphaComparator(): callable
	{
		return function (MailboxDirectory $a, MailboxDirectory $b): int {
			return $this->compareNames((string)$a->getName(), (string)$b->getName());
		};
	}

	/**
	 * @param MailboxDirectory[] $dirs
	 */
	private function getPresetComparator(array $dirs = []): callable
	{
		$dirsMap = $this->buildDirsMap($dirs);

		return function (MailboxDirectory $a, MailboxDirectory $b) use ($dirsMap): int {
			$dataA = $dirsMap[$a->getId()] ?? $this->extractDirSortingData($a);
			$dataB = $dirsMap[$b->getId()] ?? $this->extractDirSortingData($b);

			$weightA = $this->calculateWeight($dataA, $dirsMap);
			$weightB = $this->calculateWeight($dataB, $dirsMap);

			if ($weightA === $weightB)
			{
				return $this->compareNames($dataA->name, $dataB->name);
			}

			return $weightA <=> $weightB;
		};
	}

	private function compareNames(string $nameA, string $nameB): int
	{
		return mb_strtolower($nameA) <=> mb_strtolower($nameB);
	}

	/**
	 * @param MailboxDirectory[] $dirs
	 * @return array<int, DirSortingData>
	 */
	private function buildDirsMap(array $dirs): array
	{
		$map = [];
		foreach ($dirs as $dir)
		{
			$data = $this->extractDirSortingData($dir);
			$map[$data->id] = $data;
		}

		return $map;
	}

	private function extractDirSortingData(MailboxDirectory $dir): DirSortingData
	{
		$type = match (true)
		{
			$dir->isIncome() => MailboxDirectoryTable::TYPE_INCOME,
			$dir->isOutcome() => MailboxDirectoryTable::TYPE_OUTCOME,
			$dir->isDraft() => MailboxDirectoryTable::TYPE_DRAFT,
			$dir->isTrash() => MailboxDirectoryTable::TYPE_TRASH,
			$dir->isSpam() => MailboxDirectoryTable::TYPE_SPAM,
			default => null,
		};

		return new DirSortingData(
			id: $dir->getId(),
			rootId: $dir->getRootId() > 0 ? $dir->getRootId() : null,
			type: $type,
			level: $dir->getLevel(),
			name: (string)$dir->getName(),
			isVirtual: $dir->isVirtualFolder(),
		);
	}

	/**
	 * @param array<int, DirSortingData> $dirsMap
	 */
	private function calculateWeight(DirSortingData $data, array $dirsMap = []): int
	{
		$weights = $this->getProviderWeights();

		// For nested folders we use root folder's type to determine weight.
		// This ensures that child folders of system folders (e.g., subfolders of Inbox)
		// are grouped together with their parent instead of falling into "other" category.
		$effectiveData = $this->getEffectiveDataForWeight($data, $dirsMap);

		$baseWeight = null;
		$name = mb_strtolower($effectiveData->name);

		if ($effectiveData->type && isset($weights[$effectiveData->type]))
		{
			$baseWeight = $weights[$effectiveData->type];
		}
		elseif (isset($weights[$name]))
		{
			$baseWeight = $weights[$name];
		}
		elseif (isset($weights[self::DIR_TYPE_OTHER]))
		{
			$baseWeight = $weights[self::DIR_TYPE_OTHER];
		}

		if ($baseWeight === null)
		{
			$baseWeight = self::DEFAULT_BASE_WEIGHT;
		}

		return ($baseWeight * self::GROUP_MULTIPLIER) + $data->level;
	}

	/**
	 * Returns root folder's data for nested folders to inherit parent's weight.
	 *
	 * Example: subfolder "Inbox/Projects" should have weight based on "Inbox" (system folder),
	 * not default weight for "other" folders. This keeps nested folders grouped with their parent.
	 *
	 * @param array<int, DirSortingData> $dirsMap
	 */
	private function getEffectiveDataForWeight(DirSortingData $data, array $dirsMap): DirSortingData
	{
		$isRootLevel = $data->level <= 1;
		$hasNoRoot = $data->rootId === null || $data->rootId === $data->id;
		$rootFolder = $dirsMap[$data->rootId];

		if ($isRootLevel || $hasNoRoot || $rootFolder?->isVirtual)
		{
			return $data;
		}

		return $rootFolder ?? $data;
	}

	/**
	 * @return array<string, int>
	 */
	private function getProviderWeights(): array
	{
		return self::$presets[$this->providerCode] ?? self::$presets['default'];
	}

	/**
	 * @return MailboxDirectory[]
	 */
	private function sortByDbData(): array
	{
		if ($this->mailboxId > 0)
		{
			$userSortingDirs = $this->loadUserSortingDirs();
			if (!empty($userSortingDirs))
			{
				return $this->sortByCustomConfig($userSortingDirs);
			}
		}

		return [];
	}

	private function loadUserSortingDirs(): ?array
	{
		//ToDo
		return null;
	}

	/**
	 * @param array $dirs
	 * @return MailboxDirectory[]
	 */
	private function sortByCustomConfig(array $dirs): array
	{
		//ToDo
		return [];
	}
}
