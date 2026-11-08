<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\Landing\Copilot\Manager;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteRuntimeAvailabilityGuard;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteChatAvailabilityService;

trait AiSiteRuntimeAvailabilityToolGate
{
	public function canList(int $userId): bool
	{
		return parent::canList($userId) && $this->isAiSiteRuntimeAvailable();
	}

	public function canRun(int $userId): bool
	{
		return parent::canRun($userId) && $this->isAiSiteRuntimeAvailable();
	}

	protected function isAiSiteRuntimeAvailable(): bool
	{
		if (!$this->isAiSitesReleaseOptionEnabled())
		{
			return false;
		}

		$guard = $this->getAiSiteRuntimeAvailabilityGuard();
		$availability = $this->isTariffGateRequired()
			? $guard->checkProductAvailability()
			: $guard->checkStatusReadProductAvailability()
		;

		if ($availability->getReasonCode() === AiSiteChatAvailabilityService::ERROR_FEATURE_LIMITED && $this->isFeatureLimitedToolVisible())
		{
			return true;
		}

		return $availability->isAvailable();
	}

	/**
	 * Selects the availability slice used by canList()/canRun().
	 *
	 * Despite the name, the switch controls BOTH extra gates of the full product slice:
	 * returning false drops the tariff gate (feature_limited) AND the copilot tuning gate
	 * (feature_inactive), leaving only the product and rollout-option gates. Read-only
	 * status tools override it with false so that an already launched generation can still
	 * be polled after the tariff limit is exhausted or the copilot is switched off;
	 * every tool that launches or changes generation keeps the default true.
	 */
	protected function isTariffGateRequired(): bool
	{
		return true;
	}

	protected function isFeatureLimitedToolVisible(): bool
	{
		return false;
	}

	protected function isAiSitesReleaseOptionEnabled(): bool
	{
		return Manager::isAiSitesEnabled();
	}

	protected function getAiSiteRuntimeAvailabilityGuard(): AiSiteRuntimeAvailabilityGuard
	{
		return new AiSiteRuntimeAvailabilityGuard();
	}
}
