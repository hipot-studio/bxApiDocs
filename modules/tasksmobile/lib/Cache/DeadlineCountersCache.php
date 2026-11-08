<?php

declare(strict_types=1);

namespace Bitrix\TasksMobile\Cache;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;
use Bitrix\Tasks\Kanban\TimeLineTable;

/**
 * Short-TTL per-user cache of the mobile deadline-board stage counters
 * (SYSTEM_TYPE => count map), enabled by default and dropped for the acting
 * user on their own board mutations.
 *
 * @internal
 */
final class DeadlineCountersCache
{
	private const OPT_MODULE = 'tasksmobile';
	private const OPT_ENABLED = 'deadline_counters_cache_enabled';
	private const OPT_TTL = 'deadline_counters_cache_ttl';
	private const MAX_TTL = 300;
	private const BASE_DIR = '/tasksmobile/dlc';
	private const TIME_PREFIX = '~ts:';
	private const GENERATION_ID = 'generation';
	private const SLOT_PREFIX = 'slot';

	/**
	 * The board parameters of the request reach the key, and the caller may vary them at will, so an entry
	 * per variant has no ceiling: the file engine stops reading an entry once its ttl runs out but never
	 * removes the file, and only a mutation of the same user wipes the directory. The variants are therefore
	 * mapped onto a fixed number of slots, one variant at a time each, which caps what a user can leave
	 * behind at these plus the generation entry.
	 *
	 * A board landing on a slot held by a live entry of another one simply stays uncached until that entry
	 * expires — cheaper than evicting it, which would have two boards in one slot wipe each other on every
	 * request and give neither a hit. Boards of one user within a ttl are counted in units, so the number is
	 * set well above them: it is the odds of that collision, not the ceiling itself, that are worth spending on.
	 */
	private const SLOTS = 64;

	// the epoch marker outlives by far the snapshots it guards, so a live board is never invalidated by it
	private const GENERATION_TTL = 86400;

	private string $lastStatus = 'miss';

	/** @var array<int, string> generation per user, taken once and reused by the following set() */
	private array $generations = [];

	/** the day of the board, taken once and reused by the following set() */
	private ?string $clientDate = null;

	/** the timezone offset of the board, taken once and reused by the following set() */
	private ?int $clientOffset = null;

	/** the moment the board was asked for, taken once and reused by the following set() */
	private ?int $now = null;

	/** A non-positive ttl neither reads nor writes a snapshot, so the generation bookkeeping is pure overhead. */
	public function isEnabled(): bool
	{
		return Option::get(self::OPT_MODULE, self::OPT_ENABLED) === 'Y' && $this->getTtl() > 0;
	}

	public function getLastStatus(): string
	{
		return $this->lastStatus;
	}

	/** @return array|null null = miss/bypass; [] = cached empty result (empty board) */
	public function get(int $userId, int $ownerId, ?int $projectId, array $filter): ?array
	{
		if ($userId <= 0 || $this->isBypass($filter))
		{
			$this->lastStatus = 'bypass';

			return null;
		}

		$cache = $this->createCache();
		$variantKey = $this->buildVariantKey($userId, $ownerId, (int)$projectId, $filter);

		if ($cache->initCache($this->getTtl(), $this->buildSlotId($variantKey), $this->buildInitDir($userId)))
		{
			$counts = $this->readSlot($cache->getVars(), $variantKey);
			if ($counts !== null)
			{
				$this->lastStatus = 'hit';

				return $counts;
			}
		}

		$this->lastStatus = 'miss';

		return null;
	}

	public function set(int $userId, int $ownerId, ?int $projectId, array $filter, array $counts): void
	{
		if ($userId <= 0 || $this->isBypass($filter))
		{
			return;
		}

		$cache = $this->createCache();
		$variantKey = $this->buildVariantKey($userId, $ownerId, (int)$projectId, $filter);

		$cache->noOutput();
		if ($cache->startDataCache($this->getTtl(), $this->buildSlotId($variantKey), $this->buildInitDir($userId)))
		{
			$cache->endDataCache(['key' => $variantKey, 'counts' => $counts]);
		}
	}

	/**
	 * Clear all cached deadline counters of one user (all their boards). The reset writes nothing back:
	 * the generation entry lives in the very directory being wiped, and the next reader opens a fresh
	 * epoch on its own. This keeps the mutations of a user who never opens the board free of the wipe —
	 * an empty directory costs a lookup, while a directory holding an entry costs a rename and a row
	 * in b_cache_clean_path on every single mutation.
	 */
	public function clearForUser(int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		$this->createCache()->cleanDir($this->buildInitDir($userId));

		unset($this->generations[$userId]);
	}

	/**
	 * Keeps this cache out of the stale-while-revalidate mode of the portal: with a lock in play the
	 * engine serves the retired directory version for ttlOld after a reset, so a reader that loses the
	 * lock would be handed the retired generation and, with it, the very snapshot the reset dropped.
	 * A portal that turns locking on explicitly (use_lock) still wins over this — see the ADR.
	 */
	private function createCache(): Cache
	{
		return Cache::createInstance(['actual_data' => true]);
	}

	/**
	 * Single-use boards stay out of the cache: neither a filter by ID nor a search string comes back within
	 * the ttl, so their entries are never read and would only take slots from the boards that are.
	 * A date object of any other origin than the moment of the request is out for the very same reason: the
	 * board takes its dates from that moment, while a border of the caller reaches the filter as a string and
	 * never gets here, so a date standing elsewhere is one derived from the moment by an offset of its own -
	 * it moves with every request and its entries would be written once and read by nobody.
	 * Recursive, like the normalization: a preset field and the search string of the dashboard land in the
	 * '::SUBFILTER-' blocks of Filter::process().
	 */
	private function isBypass(array $filter): bool
	{
		foreach ($filter as $key => $value)
		{
			if (preg_match('/^[!=@<>*%]*(ID|FULL_SEARCH_INDEX)$/', (string)$key))
			{
				return true;
			}

			if (is_array($value))
			{
				if ($this->isBypass($value))
				{
					return true;
				}
			}
			elseif ($this->isDate($value) && !$this->isMomentOfRequest((int)$value->getTimestamp()))
			{
				return true;
			}
		}

		return false;
	}

	private function isDate(mixed $value): bool
	{
		return $value instanceof Date || $value instanceof \DateTimeInterface;
	}

	/** Within a window of it there is no telling one from the other, and a window is what the key keeps anyway. */
	private function isMomentOfRequest(int $timestamp): bool
	{
		return abs($timestamp - $this->getNow()) <= $this->getWindow();
	}

	/** TTL is the only staleness bound for mutations made outside the mobile app, so it is capped. */
	private function getTtl(): int
	{
		$ttl = (int)Option::get(self::OPT_MODULE, self::OPT_TTL);

		return min(max($ttl, 0), self::MAX_TTL);
	}

	private function buildVariantKey(int $userId, int $ownerId, int $projectId, array $filter): string
	{
		$parts = [
			$this->resolveGeneration($userId),
			$ownerId,
			$projectId,
			serialize($this->normalizeFilter($filter)),
			$this->getClientDate(),
			$this->getClientOffset(),
		];

		return md5(implode('|', $parts));
	}

	/** Seven hex digits stay within an int on any platform, and the spread of md5 is enough for the slots. */
	private function buildSlotId(string $variantKey): string
	{
		return self::SLOT_PREFIX . (hexdec(substr($variantKey, 0, 7)) % self::SLOTS);
	}

	/**
	 * A slot holds one variant at a time and states which one, so a board landing on the slot of another
	 * reads a miss and counts itself instead of being served the numbers of that other board.
	 */
	private function readSlot(mixed $entry, string $variantKey): ?array
	{
		if (!is_array($entry) || ($entry['key'] ?? null) !== $variantKey || !is_array($entry['counts'] ?? null))
		{
			return null;
		}

		return $entry['counts'];
	}

	/**
	 * Counting a board takes seven queries, and a mutation of the same user may land in the middle of them.
	 * The generation is read before the counting starts and reused by the following set(), so a snapshot
	 * taken before the reset is written under the retired generation and is never read back, instead of
	 * landing on the board that was just cleared.
	 *
	 * A missing generation opens a new one instead of falling back to a fixed value: a constant default
	 * would come back into use on every expiry of the generation entry and resurrect the snapshots
	 * written under it, which is the very thing the generation is here to prevent.
	 */
	private function resolveGeneration(int $userId): string
	{
		if (!isset($this->generations[$userId]))
		{
			$this->generations[$userId] = $this->readGeneration($userId) ?? $this->openGeneration($userId);
		}

		return $this->generations[$userId];
	}

	private function readGeneration(int $userId): ?string
	{
		$cache = $this->createCache();
		if (!$cache->initCache(self::GENERATION_TTL, self::GENERATION_ID, $this->buildInitDir($userId)))
		{
			return null;
		}

		$generation = $cache->getVars();

		return is_string($generation) ? $generation : null;
	}

	/** Opens an epoch of the user's board; a concurrently opened one wins, and then we follow it. */
	private function openGeneration(int $userId): string
	{
		$generation = uniqid('', true);

		$cache = $this->createCache();
		$cache->noOutput();
		if ($cache->startDataCache(self::GENERATION_TTL, self::GENERATION_ID, $this->buildInitDir($userId)))
		{
			$cache->endDataCache($generation);

			return $generation;
		}

		return $this->readGeneration($userId) ?? $generation;
	}

	/**
	 * Snaps the moment of the request to a window and orders keys, so that requests meaning the same board
	 * share one id: the moment carries microseconds and would otherwise give each request an entry of its
	 * own, never read by anyone. Dates standing anywhere else never reach this point - the bypass above
	 * keeps their boards out of the cache instead of rounding them together.
	 */
	private function normalizeFilter(array $filter): array
	{
		$normalized = [];

		foreach ($filter as $key => $value)
		{
			if (is_array($value))
			{
				$normalized[$key] = $this->normalizeFilter($value);
			}
			elseif ($this->isDate($value))
			{
				$normalized[$key] = $this->snapToWindow((int)$value->getTimestamp());
			}
			else
			{
				$normalized[$key] = $value;
			}
		}

		ksort($normalized);

		return $normalized;
	}

	private function snapToWindow(int $timestamp): string
	{
		$window = $this->getWindow();

		return self::TIME_PREFIX . (intdiv($timestamp, $window) * $window);
	}

	private function getWindow(): int
	{
		return max($this->getTtl(), 1);
	}

	/** the moment the board was asked for, taken once and reused by the following set(), as the day is */
	private function getNow(): int
	{
		return $this->now ??= time();
	}

	private function buildInitDir(int $userId): string
	{
		return self::BASE_DIR . '/' . $userId;
	}

	/**
	 * The board buckets are built for the client's day, and the seven counts behind them are long enough
	 * to cross its midnight. The day is taken once, before the counting, and reused by the following set(),
	 * so a snapshot stays on the board it was counted for instead of opening the next day on yesterday's spread.
	 */
	private function getClientDate(): string
	{
		return $this->clientDate ??= $this->readClientDate();
	}

	private function readClientDate(): string
	{
		if (Loader::includeModule('tasks'))
		{
			return (string)TimeLineTable::getDateClient();
		}

		return date('Y-m-d');
	}

	/**
	 * The borders inside that day are built from the moment of the client, which is the server one shifted
	 * by the offset of the user's timezone. One day seen from another offset — a second device, a changed
	 * timezone of the profile, a daylight saving switch — is a different spread, so the offset belongs in
	 * the key next to the day and is taken once for the same reason.
	 */
	private function getClientOffset(): int
	{
		return $this->clientOffset ??= (int)\CTimeZone::GetOffset();
	}
}
