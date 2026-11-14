<?php

namespace Bitrix\StaffTrack\Internal\Integration\HumanResources;

use Bitrix\Main\Data\Cache;
use Bitrix\Main\Data\TaggedCache;
use Bitrix\Main\Security\Random;

/**
 * The generation of the structure of departments: a marker with a random token, kept for an hour in the cache of the
 * portal and live while the confirmation of its token is. The confirmation is kept under a path of its token with the
 * tag the structure is reset with, so every reset of the structure by humanresources or intranet drops it, and the
 * reset of stafftrack drops the marker. An entry kept with a token of the structure is valid only while its token is
 * the live one, so a build takes the token before it reads the structure, and whatever it keeps after a reset is never
 * read back.
 */
class StructureGeneration
{
	private const TTL = 3600;
	private const DIR = '/stafftrack/subordinate_scope/generation';
	private const ENTRY_ID = 'stafftrack.structure_generation.v1';
	private const CONFIRMATION_ID = 'stafftrack.structure_generation_confirmation.v1';
	private const STRUCTURE_TAG = 'intranet_department_structure';
	private const TOKEN_LENGTH = 32;
	/** seconds no marker is written after the cache did not keep one: the rest of a hit, a minute of a long process */
	private const WRITING_PAUSE = 60;

	private static int $writingPausedUntil = 0;

	/**
	 * Returns the token of the live marker.
	 *
	 * @return string|null null when there is no live marker or the cache could not be read
	 */
	public function current(): ?string
	{
		try
		{
			return $this->liveToken();
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * Returns the token of the live marker, and without one writes a marker with a new random token.
	 *
	 * @return string|null null when the hit skips the cache, the cache could not be read or the marker could not be
	 *     written
	 */
	public function acquire(): ?string
	{
		try
		{
			// a hit skipping the cache would write a new marker over the live one: a reset for every team of the portal
			if ($this->isCacheSkipped() || self::$writingPausedUntil > time())
			{
				return null;
			}

			return $this->liveToken() ?? $this->writeMarker();
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * Drops the marker, so that no entry kept with its token is read back. The next marker is written even to a cache
	 * that did not keep the last one.
	 */
	public function reset(): void
	{
		self::$writingPausedUntil = 0;

		try
		{
			$this->clean();
		}
		catch (\Throwable)
		{
			// the marker lives until its confirmation is reset or its hour is over
		}
	}

	/**
	 * @return mixed the marker; null when there is none
	 */
	protected function load(): mixed
	{
		$cache = Cache::createInstance();

		return $cache->initCache(self::TTL, self::ENTRY_ID, self::DIR) ? $cache->getVars() : null;
	}

	/**
	 * Writes the marker over the one of a token that is not confirmed any more.
	 */
	protected function store(array $entry): void
	{
		$cache = Cache::createInstance();
		$cache->noOutput();
		$cache->forceRewriting(true);
		if ($cache->startDataCache(self::TTL, self::ENTRY_ID, self::DIR))
		{
			$cache->endDataCache($entry);
		}
	}

	protected function clean(): void
	{
		Cache::createInstance()->clean(self::ENTRY_ID, self::DIR);
	}

	/**
	 * @return mixed the confirmation of the token; null when there is none
	 */
	protected function loadConfirmation(string $token): mixed
	{
		$cache = Cache::createInstance();

		return $cache->initCache(self::TTL, self::CONFIRMATION_ID, self::getConfirmationDir($token))
			? $cache->getVars()
			: null
		;
	}

	protected function storeConfirmation(string $token): void
	{
		$cache = Cache::createInstance();
		$cache->noOutput();
		if ($cache->startDataCache(self::TTL, self::CONFIRMATION_ID, self::getConfirmationDir($token)))
		{
			$cache->endDataCache(['token' => $token]);
		}
	}

	/**
	 * Registers the tag of the structure for the path of the confirmation. The path is new, so no reset already under
	 * way deletes its tag row. The tag is registered by a tagged cache of its own: the one of the application remembers
	 * the tags it registered for the rest of the process, even after a reset made elsewhere.
	 */
	protected function tagConfirmation(string $token): void
	{
		$taggedCache = new TaggedCache();
		$taggedCache->startTagCache(self::getConfirmationDir($token));
		$taggedCache->registerTag(self::STRUCTURE_TAG);
		$taggedCache->endTagCache();
	}

	/**
	 * Whether the hit skips reading the cache of the portal, as an administrator may ask.
	 */
	protected function isCacheSkipped(): bool
	{
		return Cache::shouldClearCache();
	}

	/**
	 * @return string|null the token of the marker while the confirmation of the token lives
	 */
	private function liveToken(): ?string
	{
		$token = self::tokenOf($this->load());

		return $token !== null && $this->isConfirmed($token) ? $token : null;
	}

	/**
	 * Writes a marker with a new token, confirmed and tagged before the marker points to it. A cache that does not keep
	 * what is written is not written to again for a while: every build would write a marker of its own and register its
	 * tag in the database.
	 */
	private function writeMarker(): ?string
	{
		$token = Random::getString(self::TOKEN_LENGTH, true);
		$markerToken = null;

		try
		{
			$this->storeConfirmation($token);
			if ($this->isConfirmed($token))
			{
				// tagged once it is written: a reset that finds the tag finds the confirmation to drop
				$this->tagConfirmation($token);

				// a live marker another build wrote in the meantime is kept
				$markerToken = $this->liveToken();
				if ($markerToken !== null)
				{
					return $markerToken;
				}

				$this->store(['token' => $token]);
				$markerToken = self::tokenOf($this->load());
			}
		}
		catch (\Throwable)
		{
			// a cache that cannot be written keeps no marker
		}

		if ($markerToken === null)
		{
			self::$writingPausedUntil = time() + self::WRITING_PAUSE;

			return null;
		}

		// a reset may drop the confirmation before the marker points to it
		return $this->isConfirmed($markerToken) ? $markerToken : null;
	}

	private function isConfirmed(string $token): bool
	{
		return self::tokenOf($this->loadConfirmation($token)) === $token;
	}

	private static function getConfirmationDir(string $token): string
	{
		return self::DIR . '/' . $token;
	}

	private static function tokenOf(mixed $entry): ?string
	{
		$token = is_array($entry) ? ($entry['token'] ?? null) : null;

		return is_string($token) && $token !== '' ? $token : null;
	}
}
