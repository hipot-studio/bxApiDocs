<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Mute;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Main\Application;

/**
 * The rules of a user as one cache entry, by the user and by nothing else - unlike the folder list,
 * which is kept per nesting scope ({@see \Bitrix\Im\V2\Folder\Cache\FolderCache}). The state is
 * needed across every scope at once and on every read of the recent list, so a single entry is both
 * the cheaper read and the simpler thing to drop.
 *
 * The empty state is cached as well: a user with no rules is the common case, and without the entry
 * every request would pay a query to learn that there is nothing to apply.
 *
 * Dropped by its own key and directory and not by a tag: the directory already holds one user and
 * nothing else, so a tag would buy nothing and would cost a read and an insert into b_cache_tag on
 * every write - once per recipient on the send of a message, which reaches every member of a chat
 * at once. The folder list needs the tag for the other reason
 * ({@see \Bitrix\Im\V2\Folder\Cache\FolderCache::clearAllSystem()}): there the entries of every user
 * have to go at once, and no directory covers them.
 */
class MuteCache
{
	private const TTL = 86400;
	private const BASE_DIR = '/bx/imc/foldermute';

	/** @var array<int, array{visibilityGate: bool, state: UserMuteState}> */
	private array $inMemoryCache = [];

	public function get(int $userId): ?UserMuteState
	{
		$visibilityGate = $this->getVisibilityGate();
		$inMemoryEntry = $this->inMemoryCache[$userId] ?? null;
		if ($inMemoryEntry !== null && $inMemoryEntry['visibilityGate'] === $visibilityGate)
		{
			return $inMemoryEntry['state'];
		}
		unset($this->inMemoryCache[$userId]);

		$cache = Application::getInstance()->getCache();

		if (!$cache->initCache(self::TTL, $this->makeKey($userId), $this->makeDir($userId)))
		{
			return null;
		}

		$data = $cache->getVars();

		if (!is_array($data))
		{
			return null;
		}

		// `chat_folder_all_available` changes Default from visible to hidden and changes availability of
		// the system source folders. Keep that gate beside each user's state: a mismatch is a lazy
		// per-user invalidation, so changing the option needs no global cache-directory scan.
		if (($data['visibilityGate'] ?? null) !== $visibilityGate)
		{
			return null;
		}

		// An entry written in a shape this version does not read is a miss and not an empty state: the
		// caller then rebuilds the state from the rows and writes it back, while an empty state would
		// leave the rules of that user silencing nothing until the entry expired.
		$state = UserMuteState::fromCacheArray($data);
		if ($state === null)
		{
			return null;
		}

		$this->inMemoryCache[$userId] = ['visibilityGate' => $visibilityGate, 'state' => $state];

		return $state;
	}

	public function set(int $userId, UserMuteState $state): void
	{
		$visibilityGate = $this->getVisibilityGate();
		$this->inMemoryCache[$userId] = ['visibilityGate' => $visibilityGate, 'state' => $state];

		$cache = Application::getInstance()->getCache();

		// A live entry cannot be overwritten, only removed: `startDataCache()` answers false while one
		// exists and the write below never happens. A miss comes in two kinds, and only one of them
		// leaves no entry behind - the other is an entry of a previous format, which `get()` reads as
		// a miss but which is valid by TTL and would keep every write out for a day. Removed first,
		// so that a state rebuilt from the rows is stored and not rebuilt again on the next request.
		$cache->clean($this->makeKey($userId), $this->makeDir($userId));
		$cache->initCache(self::TTL, $this->makeKey($userId), $this->makeDir($userId));

		if (!$cache->startDataCache())
		{
			return;
		}

		$cache->endDataCache([
			...$state->toCacheArray(),
			'visibilityGate' => $visibilityGate,
		]);
	}

	public function clearByUser(int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		unset($this->inMemoryCache[$userId]);

		Application::getInstance()->getCache()->clean($this->makeKey($userId), $this->makeDir($userId));
	}

	private function makeKey(int $userId): string
	{
		return 'folder_mute_' . $userId;
	}

	private function makeDir(int $userId): string
	{
		$partition = $userId % 100;

		return self::BASE_DIR . '/' . $partition . '/' . $userId;
	}

	private function getVisibilityGate(): bool
	{
		return Features::isChatFolderAllAvailable();
	}
}
