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
	 * Cached first site generation ids for the current request, keyed by the counted scenario class.
	 * A present key means the counter of that scenario has been read, null - it has no generations.
	 * The free site is shared, yet the cache is not merged into one field: a merged empty value
	 * would take a later generation of another scenario as the first one.
	 * @var array<class-string, int|null>
	 */
	private static array $firstSiteGenerationIdCache = [];

	/**
	 * Current generation id for this request (when known).
	 */
	private static ?int $currentGenerationId = null;

	/**
	 * Check whether there were no site generations yet.
	 *
	 * The free site is one for both generations of AI sites: a portal that has spent it in one of
	 * them gets no other one after the mode is switched. Outside a generation every counter has to
	 * be empty; a known generation is the first one only when it is the earliest of all the counters,
	 * so an accepted job keeps its answer when the mode is switched.
	 *
	 * @return bool
	 */
	public static function isFirstSiteGeneration(): bool
	{
		if (self::$currentGenerationId !== null)
		{
			return self::isFirstSiteGenerationId(self::$currentGenerationId);
		}

		foreach (self::getOrderedFirstSiteGenerationScenarioClasses() as $scenarioClass)
		{
			// the counter of the selected generation of AI sites goes first: a spent free site
			// is usually found there, and the second counter is not read at all
			if (self::getFirstSiteGenerationId($scenarioClass) !== null)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Set current generation id for this request (when known).
	 *
	 * A counter read as empty earlier in the request takes the generation only when it is the counter
	 * of the generation's own scenario: written into an empty counter of another scenario, a later
	 * generation would be answered as the first one.
	 *
	 * @param int|null $generationId
	 * @param string|null $scenarioClass Scenario class of the generation, when it is already known.
	 *
	 * @return void
	 */
	public static function setCurrentGenerationId(?int $generationId, ?string $scenarioClass = null): void
	{
		self::$currentGenerationId = $generationId;

		if (
			$generationId === null
			|| $scenarioClass === null
			|| !array_key_exists($scenarioClass, self::$firstSiteGenerationIdCache)
			|| self::$firstSiteGenerationIdCache[$scenarioClass] !== null
			|| AiSiteDevBypassService::isGenerationIdMarked($generationId)
		)
		{
			return;
		}

		self::$firstSiteGenerationIdCache[$scenarioClass] = $generationId;
	}

	/**
	 * Get the id of the first site generation of the given free site scenario.
	 *
	 * The method caches the result in memory for the current request.
	 * Returns null when no generations exist.
	 *
	 * @param string $scenarioClass One of getFirstSiteGenerationScenarioClasses().
	 *
	 * @return int|null
	 */
	private static function getFirstSiteGenerationId(string $scenarioClass): ?int
	{
		if (!array_key_exists($scenarioClass, self::$firstSiteGenerationIdCache))
		{
			self::$firstSiteGenerationIdCache[$scenarioClass] = self::getFirstSiteGenerationIdFromDb($scenarioClass);
		}

		return self::$firstSiteGenerationIdCache[$scenarioClass];
	}

	/**
	 * Checking generation id, is it the earliest one of all the counters: the first generation of
	 * a scenario is not free when the other scenario has an earlier one.
	 *
	 * @param int $generationId
	 *
	 * @return bool
	 */
	private static function isFirstSiteGenerationId(int $generationId): bool
	{
		$firstGenerationId = null;

		foreach (self::getOrderedFirstSiteGenerationScenarioClasses() as $scenarioClass)
		{
			$scenarioGenerationId = self::getFirstSiteGenerationId($scenarioClass);
			if ($scenarioGenerationId === null)
			{
				continue;
			}

			if ($scenarioGenerationId < $generationId)
			{
				return false;
			}

			$firstGenerationId = min($firstGenerationId ?? $scenarioGenerationId, $scenarioGenerationId);
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
	 * @param string|null $scenarioClass Counted scenario, the one of the selected generation by default.
	 *
	 * @return int|null
	 */
	private static function getFirstSiteGenerationIdFromDb(?string $scenarioClass = null): ?int
	{
		$scenarioClass ??= self::getFirstSiteGenerationScenarioClass();
		$lastId = null;

		while (true)
		{
			$query = Copilot\Model\GenerationsTable::query()
				->setSelect(['ID', 'DATA'])
				->where('SCENARIO', '=', $scenarioClass)
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
	 * a new first-free counter with CreateAiSite. The markup generation is counted by
	 * CreateMarkupSite, but it shares the free site with CreateAiSite: the lookups stay
	 * per scenario only to keep the ordered read by the (SCENARIO, ID) index.
	 *
	 * The scenario is matched exactly, so the change scenarios inheriting these classes are not counted.
	 *
	 * @return class-string Counter of the selected generation of AI sites, the one read first.
	 */
	private static function getFirstSiteGenerationScenarioClass(): string
	{
		return Copilot\Manager::getAiSitesMode() === Copilot\Manager::AI_SITES_MODE_MARKUP
			? Copilot\Generation\Scenario\CreateMarkupSite::class
			: Copilot\Generation\Scenario\CreateAiSite::class
		;
	}

	/**
	 * @return list<class-string> Every counted scenario, the one of the selected generation first.
	 */
	private static function getOrderedFirstSiteGenerationScenarioClasses(): array
	{
		return array_values(array_unique([
			self::getFirstSiteGenerationScenarioClass(),
			...self::getFirstSiteGenerationScenarioClasses(),
		]));
	}

	/**
	 * @return list<class-string> Every scenario counted for the shared free site.
	 */
	private static function getFirstSiteGenerationScenarioClasses(): array
	{
		return [
			Copilot\Generation\Scenario\CreateAiSite::class,
			Copilot\Generation\Scenario\CreateMarkupSite::class,
		];
	}
}
