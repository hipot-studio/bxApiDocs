<?php
namespace Bitrix\Landing\AI\SiteBuilder\Tailwind;

use Bitrix\Landing\Copilot\Services\CreateAiSiteChecker;

final class TailwindRuntimeScopeService
{
	private TailwindRuntimeEligibilityService $eligibilityService;

	public function __construct(
		?CreateAiSiteChecker $checker = null,
		?TailwindRuntimeEligibilityService $eligibilityService = null,
	)
	{
		$this->eligibilityService = $eligibilityService ?? new TailwindRuntimeEligibilityService($checker);
	}

	public function isLandingSupported(int $landingId): bool
	{
		return $this->eligibilityService->isLandingSupported($landingId);
	}

	public function isSiteSupported(int $siteId): bool
	{
		return $this->eligibilityService->isSiteSupported($siteId);
	}
}
