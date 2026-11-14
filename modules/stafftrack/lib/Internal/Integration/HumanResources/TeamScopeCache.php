<?php

namespace Bitrix\StaffTrack\Internal\Integration\HumanResources;

use Bitrix\Main\Data\Cache;

/**
 * The team of a viewer without a filter, kept for an hour in the cache of the portal with the generation of the
 * structure it was built in and read back for that generation alone: a reset of the structure leaves it to be built
 * again. The team built by either value of the deputy flag is an entry of its own.
 */
class TeamScopeCache
{
	private const TTL = 3600;
	private const DIR = '/stafftrack/subordinate_scope/team';
	private const ENTRY_ID_PREFIX = 'stafftrack.team.v1.';

	/**
	 * Returns the team kept for the viewer and the value of the deputy flag in the generation.
	 *
	 * @return int[]|null null when no team of that generation is kept or the cache could not be read
	 */
	public function read(int $viewerId, bool $isDeputyAccessEnabled, string $generation): ?array
	{
		if ($viewerId <= 0)
		{
			return null;
		}

		try
		{
			$entry = $this->load($viewerId, $isDeputyAccessEnabled);
		}
		catch (\Throwable)
		{
			return null;
		}

		$isOfTheGeneration = is_array($entry)
			&& ($entry['generation'] ?? null) === $generation
			&& self::isTeam($entry['userIds'] ?? null)
		;

		return $isOfTheGeneration ? IdNormalizer::normalize($entry['userIds']) : null;
	}

	/**
	 * Keeps the team of the viewer and the value of the deputy flag for the generation, in place of the one kept for
	 * them before. A cache that could not be written leaves the next build to read the structure again.
	 *
	 * @param int[] $userIds
	 */
	public function write(int $viewerId, bool $isDeputyAccessEnabled, string $generation, array $userIds): void
	{
		if ($viewerId <= 0)
		{
			return;
		}

		try
		{
			$this->store($viewerId, $isDeputyAccessEnabled, [
				'generation' => $generation,
				'userIds' => IdNormalizer::normalize($userIds),
			]);
		}
		catch (\Throwable)
		{
			// the team is answered all the same
		}
	}

	/**
	 * @return mixed the entry kept for the viewer and the flag, of whatever generation; null when there is none
	 */
	protected function load(int $viewerId, bool $isDeputyAccessEnabled): mixed
	{
		$cache = Cache::createInstance();
		$isStored = $cache->initCache(
			self::TTL,
			self::getEntryId($viewerId, $isDeputyAccessEnabled),
			self::getDir($viewerId),
		);

		return $isStored ? $cache->getVars() : null;
	}

	/**
	 * Writes the entry over the one kept for the viewer and the flag, of whatever generation and age.
	 */
	protected function store(int $viewerId, bool $isDeputyAccessEnabled, array $entry): void
	{
		$cache = Cache::createInstance();
		$cache->noOutput();
		// the team of an earlier generation is replaced before its hour is over
		$cache->forceRewriting(true);

		$isStarted = $cache->startDataCache(
			self::TTL,
			self::getEntryId($viewerId, $isDeputyAccessEnabled),
			self::getDir($viewerId),
		);
		if ($isStarted)
		{
			$cache->endDataCache($entry);
		}
	}

	private static function getDir(int $viewerId): string
	{
		return self::DIR . '/' . substr(md5((string)$viewerId), 2, 2) . '/' . $viewerId;
	}

	private static function getEntryId(int $viewerId, bool $isDeputyAccessEnabled): string
	{
		return self::ENTRY_ID_PREFIX . $viewerId . '.' . (int)$isDeputyAccessEnabled;
	}

	/**
	 * Whether the ids are kept as a team is written: ids of users, nothing else.
	 */
	private static function isTeam(mixed $userIds): bool
	{
		if (!is_array($userIds))
		{
			return false;
		}

		foreach ($userIds as $userId)
		{
			if (!is_int($userId) || $userId <= 0)
			{
				return false;
			}
		}

		return true;
	}
}
