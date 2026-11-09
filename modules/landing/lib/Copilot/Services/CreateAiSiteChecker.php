<?php

namespace Bitrix\Landing\Copilot\Services;

use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSite;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSite;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateMarkupSite;
use Bitrix\Landing\Copilot\Model\GenerationsTable;
use Bitrix\Landing\Landing;

class CreateAiSiteChecker
{
	public const SCENARIO_FAMILY_LEGACY = 'legacy';
	public const SCENARIO_FAMILY_MARKUP = 'markup';

	/**
	 * Generation of AI sites a saved scenario class belongs to.
	 *
	 * The markup scenarios inherit the legacy create scenario, so `instanceof CreateAiSite` alone
	 * takes a markup job for a legacy one: the markup lineage is tested first. The answer depends
	 * on the saved class only, never on the selected generation.
	 *
	 * @param string $scenarioClass Class name as saved in the SCENARIO column.
	 *
	 * @return string|null One of the SCENARIO_FAMILY_* constants, null for a scenario of neither family.
	 */
	public static function getScenarioFamily(string $scenarioClass): ?string
	{
		if ($scenarioClass === '')
		{
			return null;
		}

		if (is_a($scenarioClass, CreateMarkupSite::class, true))
		{
			return self::SCENARIO_FAMILY_MARKUP;
		}

		if (
			is_a($scenarioClass, CreateAiSite::class, true)
			|| is_a($scenarioClass, ChangeAiSite::class, true)
		)
		{
			return self::SCENARIO_FAMILY_LEGACY;
		}

		return null;
	}

	/**
	 * @return list<class-string> Scenarios whose generation marks a site as AI-created.
	 */
	public static function getAiSiteScenarios(): array
	{
		return [
			CreateAiSite::class,
			CreateMarkupSite::class,
		];
	}

	/**
	 * Per-request memoization of isSiteCreated, keyed by checker class:
	 * subclasses override the underlying query and must not share results.
	 * @var array<string, array<int, bool>>
	 */
	private static array $siteCreatedCache = [];

	public function isSiteCreated(int $siteId): bool
	{
		if ($siteId <= 0)
		{
			return false;
		}

		self::$siteCreatedCache[static::class][$siteId] ??= $this->hasCreateAiSiteGeneration($siteId);

		return self::$siteCreatedCache[static::class][$siteId];
	}

	public static function clearSiteCreatedCache(?int $siteId = null): void
	{
		if ($siteId === null)
		{
			self::$siteCreatedCache = [];

			return;
		}

		foreach (self::$siteCreatedCache as $class => $cache)
		{
			unset(self::$siteCreatedCache[$class][$siteId]);
		}
	}

	public function hasCreatedSites(): bool
	{
		return $this->hasAnyCreateAiSiteGeneration();
	}

	public function isLandingCreated(int $landingId): bool
	{
		if ($landingId <= 0)
		{
			return false;
		}

		$siteId = $this->resolveSiteIdByLandingId($landingId);

		return $siteId > 0 && $this->isSiteCreated($siteId);
	}

	protected function hasCreateAiSiteGeneration(int $siteId): bool
	{
		$row = GenerationsTable::query()
			->setSelect(['ID'])
			->where('SITE_ID', '=', $siteId)
			->whereIn('SCENARIO', static::getAiSiteScenarios())
			->setLimit(1)
			->fetch()
		;

		return (bool)$row;
	}

	protected function hasAnyCreateAiSiteGeneration(): bool
	{
		$row = GenerationsTable::query()
			->setSelect(['ID'])
			->whereIn('SCENARIO', static::getAiSiteScenarios())
			->where('SITE_ID', '>', 0)
			->setLimit(1)
			->fetch()
		;

		return (bool)$row;
	}

	protected function resolveSiteIdByLandingId(int $landingId): int
	{
		$landing = Landing::createInstance($landingId, [
			'check_permissions' => false,
		]);
		if (!$landing->exist())
		{
			return 0;
		}

		return (int)$landing->getSiteId();
	}
}
