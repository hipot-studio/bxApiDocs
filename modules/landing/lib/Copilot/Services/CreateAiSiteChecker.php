<?php

namespace Bitrix\Landing\Copilot\Services;

use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSite;
use Bitrix\Landing\Copilot\Model\GenerationsTable;
use Bitrix\Landing\Landing;

class CreateAiSiteChecker
{
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
			->where('SCENARIO', '=', CreateAiSite::class)
			->setLimit(1)
			->fetch()
		;

		return (bool)$row;
	}

	protected function hasAnyCreateAiSiteGeneration(): bool
	{
		$row = GenerationsTable::query()
			->setSelect(['ID'])
			->where('SCENARIO', '=', CreateAiSite::class)
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
