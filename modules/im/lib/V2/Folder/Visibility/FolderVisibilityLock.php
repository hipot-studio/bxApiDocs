<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Visibility;

use Bitrix\Main\DB\Connection;

/**
 * One lock per owner over the state the invariant of a hidden folder is answered against: the
 * hidden flags of the folders and the composition of "All". Every write of either takes it and
 * reads that state again under it, so a hide and a save of the composition from two devices can
 * not both pass their checks against the state from before the other one.
 *
 * Re-entrant within a hit: hiding writes the composition through
 * {@see \Bitrix\Im\V2\Folder\Source\FolderSourceService}, which takes the same lock.
 */
class FolderVisibilityLock
{
	private const TIMEOUT = 3;

	/** @var array<int, int> user id => how many holders of this hit share the lock */
	private array $depthByUserId = [];

	public function __construct(
		private readonly Connection $connection,
	) {}

	public function acquire(int $userId): bool
	{
		if (isset($this->depthByUserId[$userId]))
		{
			$this->depthByUserId[$userId]++;

			return true;
		}

		if (!$this->connection->lock(self::getName($userId), self::TIMEOUT))
		{
			return false;
		}

		$this->depthByUserId[$userId] = 1;

		return true;
	}

	public function release(int $userId): void
	{
		if (!isset($this->depthByUserId[$userId]))
		{
			return;
		}

		$this->depthByUserId[$userId]--;
		if ($this->depthByUserId[$userId] > 0)
		{
			return;
		}

		unset($this->depthByUserId[$userId]);
		$this->connection->unlock(self::getName($userId));
	}

	private static function getName(int $userId): string
	{
		return "im_folder_visibility_{$userId}";
	}
}
