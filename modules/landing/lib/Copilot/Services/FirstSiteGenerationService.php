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
	 * Size of a single keyset portion of the first generation lookup.
	 */
	private const LOOKUP_CHUNK_SIZE = 20;

	/**
	 * Lifetime of the cross-request cache of a single lookup portion, in seconds.
	 */
	private const CROSS_REQUEST_CACHE_TTL = 86400;

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
			|| AiSiteDevBypassService::isGenerationIdMarked($generationId)
			|| self::$isFirstSiteGenerationIdCacheLoaded === false
			|| self::$firstSiteGenerationIdCache !== null
		)
		{
			return;
		}

		self::$firstSiteGenerationIdCache = $generationId;
	}

	/**
	 * Get the id of the first site generation for the free site scenario.
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
	 * The table is read in keyset portions ordered by ID, so a portal without dev bypass
	 * generations is answered by the very first portion. Dev bypass rows are skipped in PHP - the
	 * mark lives inside the serialized DATA and is not filterable in SQL - and the cursor moves
	 * only over them, so later portions exist on dev portals only. Cost of the portions and the
	 * plan they get: .ai/knowledge-base/notes/ai-sites-limits-and-toggles.md.
	 *
	 * The selection of a portion is cached across requests, and what lands in the cache is the
	 * portion itself - up to LOOKUP_CHUNK_SIZE rows with their DATA, not the resulting id - so a miss
	 * pays for writing it. The core drops the entity cache on every write to the table made through
	 * DataManager, so no manual invalidation is needed here; the 30 second window of the deferred
	 * ManagedCache write is a deliberately accepted trade-off.
	 *
	 * setCacheTtl() stands inside the walk, so every portion is cached under a key of its own,
	 * ManagedCache holds them all in the memory of the process until the epilogue of the request and
	 * writes them one by one there: a portal whose leading rows are dev bypass marked pays for the
	 * whole walk and not for a single portion.
	 *
	 * @return int|null
	 */
	private static function getFirstSiteGenerationIdFromDb(): ?int
	{
		$lastId = null;

		while (true)
		{
			$query = Copilot\Model\GenerationsTable::query()
				->setSelect(['ID', 'DATA'])
				->where('SCENARIO', '=', self::getFirstSiteGenerationScenarioClass())
				->setOrder(['ID' => 'ASC'])
				->setLimit(self::LOOKUP_CHUNK_SIZE)
				->setCacheTtl(self::CROSS_REQUEST_CACHE_TTL)
			;

			if ($lastId !== null)
			{
				// only when the cursor has really moved: an always-on ID predicate filters nothing,
				// yet it makes the primary key a cheap range for the optimizer, which then drops
				// the (SCENARIO, ID) index. Hence the idle cursor is null and not 0
				$query->where('ID', '>', $lastId);
			}

			$rows = $query->fetchAll();

			if (empty($rows))
			{
				return null;
			}

			foreach ($rows as $row)
			{
				if (AiSiteDevBypassService::isGenerationDataMarked($row['DATA'] ?? null))
				{
					$lastId = (int)$row['ID'];

					continue;
				}

				return (int)$row['ID'];
			}
		}
	}

	/**
	 * Legacy AI site generation used CreateSite; Sites AI 2.0 intentionally starts
	 * a new first-free counter with CreateAiSite.
	 */
	private static function getFirstSiteGenerationScenarioClass(): string
	{
		return Copilot\Generation\Scenario\CreateAiSite::class;
	}
}
