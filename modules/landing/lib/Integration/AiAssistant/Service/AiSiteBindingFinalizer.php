<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Service;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSite;
use Bitrix\Landing\Integration\AiAssistant\AiSiteGenerationHelper;

final class AiSiteBindingFinalizer
{
	private AiSiteBindingService $bindingService;

	public function __construct(?AiSiteBindingService $bindingService = null)
	{
		$this->bindingService = $bindingService ?? new AiSiteBindingService();
	}

	public function finalizeCompletedGeneration(Generation $generation): bool
	{
		if (!$this->canFinalize($generation))
		{
			return false;
		}

		$generationId = (int)($generation->getId() ?? 0);
		$userId = $generation->getAuthorId();
		[$siteId, $pageId] = AiSiteGenerationHelper::extractFinalIds($generation);
		if ($generationId <= 0 || $userId <= 0 || $siteId <= 0 || $pageId <= 0)
		{
			return false;
		}

		$binding = $this->bindingService->findByGenerationId($generationId);
		if ($binding === null || (int)$binding['USER_ID'] !== $userId)
		{
			return false;
		}

		$currentSiteId = (int)($binding['SITE_ID'] ?? 0);
		if ($currentSiteId === $siteId)
		{
			return true;
		}

		if ($currentSiteId > 0)
		{
			return false;
		}

		if ($this->bindingService->completeGeneration($generationId, $userId, $siteId))
		{
			return true;
		}

		return $this->isAlreadyFinalized($generationId, $userId, $siteId);
	}

	private function canFinalize(Generation $generation): bool
	{
		if (!$generation->getScenario() instanceof CreateAiSite)
		{
			return false;
		}

		if ($generation->isError() || !$generation->isFinished())
		{
			return false;
		}

			return true;
		}

	private function isAlreadyFinalized(int $generationId, int $userId, int $siteId): bool
	{
		$binding = $this->bindingService->findByGenerationId($generationId);

		return $binding !== null
			&& (int)$binding['USER_ID'] === $userId
			&& (int)($binding['SITE_ID'] ?? 0) === $siteId
		;
	}
}
