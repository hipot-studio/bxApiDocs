<?php
namespace Bitrix\Landing\AI\SiteBuilder\Tailwind;

use Bitrix\Landing\Copilot\Manager as CopilotManager;
use Bitrix\Landing\Copilot\Services\CreateAiSiteChecker;
use Bitrix\Landing\Internals\SiteTable;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Site\Type as SiteType;

class TailwindRuntimeEligibilityService
{
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

		return $this->isSiteSupported($siteId);
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
