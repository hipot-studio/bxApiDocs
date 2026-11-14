<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Service\CheckIn;

use Bitrix\Main\Data\Cache;

/**
 * Whom the backfill of the daily statistics found no check-in of a date closed to new points for, kept for the date
 * and the employee rather than for a team: a reading of the date skips whoever any reading before it has scanned, so
 * the presence list of one manager does not scan again the employees the list of another one has scanned already.
 *
 * A date keeps a bit for every employee, the ids cut into entries of {@see IDS_PER_ENTRY}: a team is looked up in one
 * reading of the cache for every entry its ids fall in - one on a portal of fewer users than that, whatever the size
 * of the team. An entry is IDS_PER_ENTRY / 8 bytes at most, far below the limit of an item of memcached (1 MB), and is
 * kept in base64: the file cache writes a NUL byte as ten.
 *
 * Nothing kept has to be taken back: every point a closed date still gets - the LEAVE admitted past the window among
 * them - writes the row of its date with itself, and an employee with a row is not looked up. Only rows taken away
 * past the write path - by the dev pages that write points straight into the table - need the date forgotten
 * ({@see forget()}). Two requests adding to an entry at once may lose what one of them added: those employees are
 * scanned again, while nobody is ever kept whom a backfill did not find nothing for.
 */
class EmptyBackFillMemory
{
	/** The ids an entry keeps a bit for: 128 KB of bits at most, 171 KB in base64. */
	private const IDS_PER_ENTRY = 1048576;

	/** A directory to a date, so that what a date keeps is dropped with one cleanDir. */
	private const DIR = '/stafftrack/daily_stats_backfill';

	/** Thirty days from the last addition, as long as the map of such a date is cached. */
	private const TTL = 2592000;

	/**
	 * @param string $localDate 'Y-m-d', closed to new points
	 * @param int[] $userIds
	 * @return int[] those of them a backfill of the date has found nothing for
	 */
	public function recall(string $localDate, array $userIds): array
	{
		$bitsByEntry = [];
		$recalledUserIds = [];
		foreach ($userIds as $userId)
		{
			if ($userId <= 0)
			{
				continue;
			}

			$entry = intdiv($userId, self::IDS_PER_ENTRY);
			$bitsByEntry[$entry] ??= $this->read($localDate, $entry);
			if (self::hasBit($bitsByEntry[$entry], $userId % self::IDS_PER_ENTRY))
			{
				$recalledUserIds[] = $userId;
			}
		}

		return $recalledUserIds;
	}

	/**
	 * Keeps the employees as found with nothing on the date, beside whomever the date keeps already.
	 *
	 * @param string $localDate 'Y-m-d', closed to new points
	 * @param int[] $userIds
	 */
	public function remember(string $localDate, array $userIds): void
	{
		$positionsByEntry = [];
		foreach ($userIds as $userId)
		{
			if ($userId > 0)
			{
				$positionsByEntry[intdiv($userId, self::IDS_PER_ENTRY)][] = $userId % self::IDS_PER_ENTRY;
			}
		}

		foreach ($positionsByEntry as $entry => $positions)
		{
			// read anew rather than taken from the recall: whom a request in parallel has added meanwhile stays kept
			$keptBits = $this->read($localDate, $entry);
			$bits = str_pad($keptBits, (max($positions) >> 3) + 1, "\0");
			foreach ($positions as $position)
			{
				$bits[$position >> 3] = chr(ord($bits[$position >> 3]) | (1 << ($position & 7)));
			}

			if ($bits !== $keptBits)
			{
				$this->write($localDate, $entry, $bits);
			}
		}
	}

	/**
	 * Forgets whom the date keeps: its next reading scans again whoever has no row of it. For whoever takes rows of
	 * the date away for the backfill to write them anew, as the dev pages writing points straight into the table do.
	 *
	 * @param string $localDate 'Y-m-d'
	 */
	public function forget(string $localDate): void
	{
		Cache::createInstance()->cleanDir(self::dirOf($localDate));
	}

	/**
	 * @return mixed the entry as it is kept, null when there is none
	 */
	protected function load(string $dir, string $entryId): mixed
	{
		$cache = Cache::createInstance();

		return $cache->initCache(self::TTL, $entryId, $dir) ? $cache->getVars() : null;
	}

	/**
	 * Writes the entry over the one kept, every bit of which it holds as well.
	 */
	protected function store(string $dir, string $entryId, array $entry): void
	{
		$cache = Cache::createInstance();
		// otherwise startDataCache() finds the entry kept and leaves it as it is
		$cache->forceRewriting(true);
		if ($cache->startDataCache(self::TTL, $entryId, $dir))
		{
			$cache->endDataCache($entry);
		}
	}

	/**
	 * @return string the bits of the entry; none when the date keeps nothing there or the entry cannot be read
	 */
	private function read(string $localDate, int $entry): string
	{
		try
		{
			$kept = $this->load(self::dirOf($localDate), self::entryIdOf($entry));
		}
		catch (\Throwable)
		{
			return '';
		}

		$bits = is_array($kept) && is_string($kept['bits'] ?? null) ? base64_decode($kept['bits'], true) : false;

		return is_string($bits) ? $bits : '';
	}

	private function write(string $localDate, int $entry, string $bits): void
	{
		try
		{
			$this->store(self::dirOf($localDate), self::entryIdOf($entry), ['bits' => base64_encode($bits)]);
		}
		catch (\Throwable)
		{
			// whom the entry could not keep is scanned again
		}
	}

	private static function dirOf(string $localDate): string
	{
		return self::DIR . '/' . $localDate;
	}

	/**
	 * An entry is named by the first and the last id it keeps, so that entries cut otherwise are never read as these.
	 */
	private static function entryIdOf(int $entry): string
	{
		$firstId = $entry * self::IDS_PER_ENTRY;

		return 'nothing_found_' . $firstId . '_' . ($firstId + self::IDS_PER_ENTRY - 1);
	}

	private static function hasBit(string $bits, int $position): bool
	{
		$byte = $position >> 3;

		return $byte < strlen($bits) && (ord($bits[$byte]) & (1 << ($position & 7))) !== 0;
	}
}
