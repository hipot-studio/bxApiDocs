<?php
namespace Bitrix\Landing\AI\SiteBuilder\Tailwind;

use Bitrix\Landing\Copilot\Manager as CopilotManager;
use Bitrix\Landing\Copilot\Services\CreateAiSiteChecker;
use Bitrix\Landing\Internals\SiteTable;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Markup\MarkupPage;
use Bitrix\Landing\Site\Type as SiteType;

class TailwindRuntimeEligibilityService
{
	/**
	 * Per-request memoization of the page format, keyed by service class: every consumer creates
	 * its own instance, and subclasses override the underlying query and must not share results.
	 * @var array<string, array<int, bool>>
	 */
	private static array $markupLandingCache = [];

	private CreateAiSiteChecker $createAiSiteChecker;

	public function __construct(?CreateAiSiteChecker $createAiSiteChecker = null)
	{
		$this->createAiSiteChecker = $createAiSiteChecker ?? new CreateAiSiteChecker();
	}

	public function isLandingSupported(int $landingId): bool
	{
		if ($landingId <= 0)
		{
			return false;
		}

		$siteId = $this->resolveLandingSiteId($landingId);
		if ($siteId <= 0)
		{
			return false;
		}

		// The runtime follows the format of the page: a markup page of an AI site ships its own styles.
		return $this->isSiteSupported($siteId) && !$this->isMarkupLandingCached($landingId);
	}

	/**
	 * Drops the memoized page format. The markup intake calls it when a page becomes a markup one.
	 */
	public static function clearMarkupLandingCache(?int $landingId = null): void
	{
		if ($landingId === null)
		{
			self::$markupLandingCache = [];

			return;
		}

		foreach (self::$markupLandingCache as $class => $cache)
		{
			unset(self::$markupLandingCache[$class][$landingId]);
		}
	}

	public function isSiteSupported(int $siteId): bool
	{
		if ($siteId <= 0 || !$this->isAiSitesEnabled())
		{
			return false;
		}

		$site = $this->fetchSiteRow($siteId);
		if ($site === null || $this->isForbiddenSiteScope($site))
		{
			return false;
		}

		return $this->createAiSiteChecker->isSiteCreated($siteId);
	}

	protected function isAiSitesEnabled(): bool
	{
		return CopilotManager::isAiSitesEnabled();
	}

	private function isMarkupLandingCached(int $landingId): bool
	{
		self::$markupLandingCache[static::class][$landingId] ??= $this->isMarkupLanding($landingId);

		return self::$markupLandingCache[static::class][$landingId];
	}

	protected function isMarkupLanding(int $landingId): bool
	{
		return MarkupPage::isMarkupLanding($landingId);
	}

	protected function resolveLandingSiteId(int $landingId): int
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

	protected function fetchSiteRow(int $siteId): ?array
	{
		$row = SiteTable::query()
			->setSelect(['ID', 'TYPE', 'CODE'])
			->where('ID', '=', $siteId)
			->setLimit(1)
			->fetch()
		;

		return is_array($row) ? $row : null;
	}

	private function isForbiddenSiteScope(array $site): bool
	{
		$type = mb_strtoupper(trim((string)($site['TYPE'] ?? '')));
		if (in_array($type, [
			SiteType::SCOPE_CODE_GROUP,
			SiteType::SCOPE_CODE_KNOWLEDGE,
			SiteType::SCOPE_CODE_VIBE,
			'STORE',
		], true))
		{
			return true;
		}

		return SiteType::getSiteSpecialType((string)($site['CODE'] ?? '')) === SiteType::PSEUDO_SCOPE_CODE_FORMS;
	}
}
