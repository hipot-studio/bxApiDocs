<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Service;

use Bitrix\Landing\Integration\AiAssistant\Dto\AiSiteChatAvailabilityResult;
use Bitrix\Main\Loader;

class AiSiteRuntimeAvailabilityGuard
{
	public const ERROR_AI_MODULE_MISSING = 'ai_module_missing';

	public function __construct(
		private readonly ?AiSiteChatAvailabilityService $availabilityService = null,
	)
	{
	}

	public function checkProductAvailability(): AiSiteChatAvailabilityResult
	{
		return $this->getAvailabilityService()->checkSitesAiProductAvailability();
	}

	public function checkRuntimeAvailability(): AiSiteChatAvailabilityResult
	{
		$productAvailability = $this->checkProductAvailability();
		if (!$productAvailability->isAvailable())
		{
			return $productAvailability;
		}

		return $this->checkAiModuleAvailability();
	}

	public function checkAiModuleAvailability(): AiSiteChatAvailabilityResult
	{
		if ($this->includeAiModule())
		{
			return AiSiteChatAvailabilityResult::available();
		}

		return AiSiteChatAvailabilityResult::unavailable(self::ERROR_AI_MODULE_MISSING);
	}

	protected function includeAiModule(): bool
	{
		return Loader::includeModule('ai');
	}

	private function getAvailabilityService(): AiSiteChatAvailabilityService
	{
		return $this->availabilityService ?? new AiSiteChatAvailabilityService();
	}
}
