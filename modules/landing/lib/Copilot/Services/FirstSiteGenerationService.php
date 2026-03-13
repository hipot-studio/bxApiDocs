<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Services;

use Bitrix\Landing\Copilot;

/**
 * Service for resolving the first site generation id.
 */
class FirstSiteGenerationService
{
	/**
	 * Cached first site generation id for the current request.
	 */
	private static ?int $firstSiteGenerationIdCache = null;

	/**
	 * Current generation id for this request (when known).
	 */
	private static ?int $currentGenerationId = null;

	/**
	 * Indicates whether the cache has been initialized for the current request.
	 */
	private static bool $isFirstSiteGenerationIdCacheLoaded = false;

	/**
	 * Check whether there were no site generations yet.
	 *
	 * @return bool
	 */
	public static function isFirstSiteGeneration(): bool
	{
		if (self::$currentGenerationId !== null)
		{
			return self::isFirstSiteGenerationId(self::$currentGenerationId);
		}

		return self::getFirstSiteGenerationId() === null;
	}

	/**
	 * Set current generation id for this request (when known).
	 *
	 * @param int|null $generationId
	 *
	 * @return void
	 */
	public static function setCurrentGenerationId(?int $generationId): void
	{
		self::$currentGenerationId = $generationId;

		if (
			$generationId === null
			|| self::$isFirstSiteGenerationIdCacheLoaded === false
			|| self::$firstSiteGenerationIdCache !== null
		)
		{
			return;
		}

		self::$firstSiteGenerationIdCache = $generationId;
	}

	/**
	 * Get the id of the first site generation for the create-site scenario.
	 *
	 * The method caches the result in memory for the current request.
	 * Returns null when no generations exist.
	 *
	 * @return int|null
	 */
	private static function getFirstSiteGenerationId(): ?int
	{
		if (self::$isFirstSiteGenerationIdCacheLoaded)
		{
			return self::$firstSiteGenerationIdCache;
		}

		self::$firstSiteGenerationIdCache = self::getFirstSiteGenerationIdFromDb();
		self::$isFirstSiteGenerationIdCacheLoaded = true;

		return self::$firstSiteGenerationIdCache;
	}

	/**
	 * Checking generation id, is it the first
	 *
	 * @param int $generationId
	 *
	 * @return bool
	 */
	private static function isFirstSiteGenerationId(int $generationId): bool
	{
		$firstGenerationId = self::getFirstSiteGenerationId();

		if ($firstGenerationId === null)
		{
			return false;
		}

		return $firstGenerationId === $generationId;
	}

	/**
	 * Get first site generation id from DB.
	 *
	 * @return int|null
	 */
	private static function getFirstSiteGenerationIdFromDb(): ?int
	{
		$row = Copilot\Model\GenerationsTable::query()
			->setSelect(['ID'])
			->where('SCENARIO', '=', Copilot\Generation\Scenario\CreateSite::class)
			->setOrder(['ID' => 'ASC'])
			->setLimit(1)
			->setCacheTtl(86400)
			->fetch()
		;

		return $row ? (int)$row['ID'] : null;
	}
}
