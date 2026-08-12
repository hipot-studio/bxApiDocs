<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\Landing\Copilot\Manager;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteRuntimeAvailabilityGuard;

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
		return $this->isAiSitesReleaseOptionEnabled()
			&& $this->getAiSiteRuntimeAvailabilityGuard()->checkProductAvailability()->isAvailable()
		;
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
