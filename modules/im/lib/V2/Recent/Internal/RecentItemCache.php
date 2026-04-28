<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Recent\Internal;

use Bitrix\Im\V2\Recent\RecentItem;

class RecentItemCache
{
	/**
	 * userId => [chatId => RecentItem|null]
	 * @var array<int, array<int, RecentItem|null>>
	 */
	private array $cache = [];

	public function has(int $userId, int $chatId): bool
	{
		return isset($this->cache[$userId]) && array_key_exists($chatId, $this->cache[$userId]);
	}

	public function get(int $userId, int $chatId): ?RecentItem
	{
		return $this->cache[$userId][$chatId] ?? null;
	}

	public function set(int $userId, int $chatId, RecentItem $item): void
	{
		$this->cache[$userId][$chatId] = $item;
	}

	public function setMissing(int $userId, int $chatId): void
	{
		$this->cache[$userId][$chatId] = null;
	}

	public function remove(?int $userId = null, ?int $chatId = null): void
	{
		if ($userId === null)
		{
			$this->cache = [];
		}
		elseif ($chatId === null)
		{
			unset($this->cache[$userId]);
		}
		else
		{
			unset($this->cache[$userId][$chatId]);
		}
	}
}
