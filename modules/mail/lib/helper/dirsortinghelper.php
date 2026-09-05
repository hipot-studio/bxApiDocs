<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper;

use Bitrix\Mail\Helper\Dto\DirSortingData;
use Bitrix\Mail\Helper\Enum\Mailbox\EntityOptionsType;
use Bitrix\Mail\Helper\Enum\Mailbox\FolderSortMode;
use Bitrix\Mail\Helper\Mailbox\Options\EntityDataHelper;
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
	private ?int $userId;

	public function __construct(int $mailboxId, string $providerCode = 'default', ?int $userId = null)
	{
		$this->mailboxId = $mailboxId;
		$this->providerCode = $providerCode;
		$this->userId = $userId;
	}

	/**
	 * @param MailboxDirectory[] $dirs
	 * @param string $strategy
	 * @return MailboxDirectory[]
	 */
	public function order(array $dirs, string $strategy = self::STRATEGY_PRESET): array
	{
		$sortedDirsByDbData = $this->sortByDbData($dirs);

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
		// type !== null is equivalent to MailboxDirectory::isSystem(); the concrete type is kept here to drive weight.
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
		$rootFolder = $dirsMap[$data->rootId] ?? null;

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
	 * @param MailboxDirectory[] $dirs
	 * @return MailboxDirectory[]
	 */
	private function sortByDbData(array $dirs): array
	{
		if ($this->mailboxId <= 0)
		{
			return [];
		}

		$order = $this->loadUserSortingDirs();
		if ($order === null)
		{
			return [];
		}

		return $this->sortByCustomConfig($dirs, $order);
	}

	/**
	 * @return array{all: int[]}|array{system: int[], custom: int[]}|null
	 */
	private function loadUserSortingDirs(): ?array
	{
		if ($this->userId === null)
		{
			return null;
		}

		$options = EntityDataHelper::getValues(
			$this->mailboxId,
			EntityOptionsType::User,
			(string)$this->userId,
			[
				EntityDataHelper::FOLDER_SORT_MODE,
				EntityDataHelper::FOLDER_CUSTOM_ORDER,
			],
		);

		if (($options[EntityDataHelper::FOLDER_SORT_MODE] ?? null) !== FolderSortMode::Manual->value)
		{
			return null;
		}

		$decoded = json_decode((string)($options[EntityDataHelper::FOLDER_CUSTOM_ORDER] ?? ''), true);
		if (!is_array($decoded))
		{
			return null;
		}

		$all = $this->normalizeIdList($decoded['all'] ?? null);
		if ($all !== [])
		{
			return ['all' => $all];
		}

		$system = $this->normalizeIdList($decoded['system'] ?? null);
		$custom = $this->normalizeIdList($decoded['custom'] ?? null);
		if ($system === [] && $custom === [])
		{
			return null;
		}

		return ['system' => $system, 'custom' => $custom];
	}

	/**
	 * @param MailboxDirectory[] $dirs
	 * @param array{all: int[]}|array{system: int[], custom: int[]} $order
	 * @return MailboxDirectory[]
	 */
	private function sortByCustomConfig(array $dirs, array $order): array
	{
		if (isset($order['all']))
		{
			return $this->applyOrder($dirs, $order['all']);
		}

		return $this->sortByLegacyCustomConfig($dirs, $order);
	}

	/**
	 * Keeps settings saved before the unified order format readable.
	 *
	 * @param MailboxDirectory[] $dirs
	 * @param array{system: int[], custom: int[]} $order
	 * @return MailboxDirectory[]
	 */
	private function sortByLegacyCustomConfig(array $dirs, array $order): array
	{
		$dirsMap = $this->buildDirsMap($dirs);
		$weights = $this->getProviderWeights();
		$customWeight = $weights[self::DIR_TYPE_OTHER] ?? null;

		$systemTop = [];
		$custom = [];
		$systemBottom = [];
		foreach ($dirs as $dir)
		{
			$data = $dirsMap[$dir->getId()] ?? $this->extractDirSortingData($dir);
			$effectiveData = $this->getEffectiveDataForWeight($data, $dirsMap);
			$typeWeight = $effectiveData->type === null ? null : ($weights[$effectiveData->type] ?? null);

			match (true)
			{
				$effectiveData->type === null => $custom[] = $dir,
				$customWeight !== null && ($typeWeight === null || $typeWeight >= $customWeight) => $systemBottom[] = $dir,
				default => $systemTop[] = $dir,
			};
		}

		// Keep the former three-block layout for settings saved before the unified format.
		$systemOrder = $order['system'] ?? [];
		$systemTop = $this->applyOrder($systemTop, $systemOrder);
		$systemBottom = $this->applyOrder($systemBottom, $systemOrder);
		$custom = $this->applyOrder($custom, $order['custom'] ?? []);

		// Blocks keep preset order (top system, then custom, then bottom system);
		// only within-block order is user-defined.
		return array_merge($systemTop, $custom, $systemBottom);
	}

	/**
	 * Orders a group by the user-defined id list. Ids present in the list keep that order;
	 * the rest go after them, sorted by preset weight (unchanged behaviour).
	 *
	 * @param MailboxDirectory[] $group
	 * @param int[] $ids
	 * @return MailboxDirectory[]
	 */
	private function applyOrder(array $group, array $ids): array
	{
		if (empty($group) || empty($ids))
		{
			return $this->sortByStrategy($group);
		}

		$positions = array_flip($ids);

		$known = [];
		$unknown = [];
		foreach ($group as $dir)
		{
			if (isset($positions[$dir->getId()]))
			{
				$known[] = $dir;
			}
			else
			{
				$unknown[] = $dir;
			}
		}

		usort(
			$known,
			static fn (MailboxDirectory $a, MailboxDirectory $b): int
				=> $positions[$a->getId()] <=> $positions[$b->getId()],
		);

		return array_merge($known, $this->sortByStrategy($unknown));
	}

	/**
	 * @return int[]
	 */
	private function normalizeIdList(mixed $ids): array
	{
		if (!is_array($ids))
		{
			return [];
		}

		$result = [];
		foreach ($ids as $id)
		{
			if (is_numeric($id) && (int)$id > 0)
			{
				$result[] = (int)$id;
			}
		}

		return $result;
	}
}
