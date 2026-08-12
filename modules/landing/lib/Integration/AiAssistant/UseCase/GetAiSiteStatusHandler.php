<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSite;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Manager as CopilotManager;
use Bitrix\Landing\Integration\AiAssistant\AiSiteGenerationHelper;
use Bitrix\Landing\Integration\AiAssistant\Dto\GetAiSiteStatusDto;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteBindingFinalizer;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteBindingService;

class GetAiSiteStatusHandler
{
	public function handle(GetAiSiteStatusDto $dto, int $userId): array
	{
		$generationId = $this->parseGenerationId($dto->jobId);
		if ($generationId <= 0)
		{
			throw new \RuntimeException('Site generation job was not found.');
		}

		if (!$this->isAiSitesEnabled())
		{
			throw new \RuntimeException('AI sites are disabled.');
		}

		if ($dto->seconds > 0)
		{
			$this->waitForSeconds($dto->seconds);
		}

		$generation = $this->loadGeneration($generationId, $userId);

		if ($generation->isError())
		{
			$this->releaseDraftOnModerationBlock($generation);

			$limitError = AiSiteGenerationHelper::extractLimitError($generation);
			$creationJournal = AiSiteGenerationHelper::extractCreationJournal($generation);
			if ($limitError !== null || (!empty($creationJournal['recoverablePartial']) && (int)$creationJournal['siteId'] > 0))
			{
				$recoverablePartial = !empty($creationJournal['recoverablePartial']) && (int)$creationJournal['siteId'] > 0;

				return AiSiteGenerationHelper::buildStatusResponse(
					jobId: $dto->jobId,
					status: 'failed',
					message: $recoverablePartial
						? 'Site generation failed after creating a draft site.'
						: 'Site generation failed.',
					error: AiSiteGenerationHelper::resolveFailureMessage($generation),
					creationJournal: $creationJournal,
					limitError: $limitError,
				);
			}

			throw new \RuntimeException(AiSiteGenerationHelper::resolveFailureMessage($generation));
		}

		[$siteId, $pageId] = AiSiteGenerationHelper::extractFinalIds($generation);
		if ($generation->isFinished())
		{
			if ($siteId > 0 && $pageId > 0)
			{
				if (!$this->finalizeCompletedGenerationBinding($generation))
				{
					throw new \RuntimeException('Failed to finalize AI site binding before returning completed status.');
				}

				$publication = AiSiteGenerationHelper::extractPublication($generation);
				$creationJournal = AiSiteGenerationHelper::extractCreationJournal($generation);
				$generationSummary = AiSiteGenerationHelper::extractGenerationSummary($generation);

				return AiSiteGenerationHelper::buildStatusResponse(
					jobId: $dto->jobId,
					status: 'completed',
					siteId: $siteId,
					pageId: $pageId,
					message: 'Site generation completed.',
					publication: $publication,
					creationJournal: $creationJournal,
					generationSummary: $generationSummary,
				);
			}

			throw new \RuntimeException('Site generation finished without final site identifiers.');
		}

		return AiSiteGenerationHelper::buildStatusResponse(
			jobId: $dto->jobId,
			status: 'running',
			message: 'Site is still generating. Call get_ai_site_status again immediately with the same jobId and seconds: 20. Site generation can take several minutes.',
		);
	}

	protected function waitForSeconds(int $seconds): void
	{
		sleep($seconds);
	}

	protected function isAiSitesEnabled(): bool
	{
		return CopilotManager::isAiSitesEnabled();
	}

	private function parseGenerationId(string $jobId): int
	{
		if (!preg_match('/^[1-9][0-9]*$/', $jobId))
		{
			return 0;
		}

		return (int)$jobId;
	}

	protected function loadGeneration(int $generationId, int $userId): Generation
	{
		$generation = new Generation();
		if (!$generation->initById($generationId))
		{
			throw new \RuntimeException('Site generation job was not found.');
		}

		if (!$generation->getScenario() instanceof CreateAiSite)
		{
			throw new \RuntimeException('Site generation job does not belong to the expected scenario.');
		}

		if (!$this->canAccessGeneration($generation, $userId))
		{
			throw new \RuntimeException('Access denied for this site generation job.');
		}

		return $generation;
	}

	protected function finalizeCompletedGenerationBinding(Generation $generation): bool
	{
		if ($this->getAiSiteBindingFinalizer()->finalizeCompletedGeneration($generation))
		{
			return true;
		}

		return CreateAiSiteState::getAiSiteBindingId($generation) <= 0;
	}

	protected function getAiSiteBindingFinalizer(): AiSiteBindingFinalizer
	{
		return new AiSiteBindingFinalizer();
	}

	protected function getAiSiteBindingService(): AiSiteBindingService
	{
		return new AiSiteBindingService();
	}

	/**
	 * On a moderation block, free the reserved draft (so the same chat can retry) and bump the
	 * consecutive-block counter. No-op for technical failures and for non-moderation errors.
	 */
	protected function releaseDraftOnModerationBlock(Generation $generation): void
	{
		if (!AiSiteGenerationHelper::isModerationBlockError($generation))
		{
			return;
		}

		$this->getAiSiteBindingService()->registerModerationBlock(
			(int)($generation->getId() ?? 0),
			$generation->getAuthorId(),
		);
	}

	private function canAccessGeneration(Generation $generation, int $userId): bool
	{
		$authorId = $generation->getAuthorId();

		return $authorId > 0 && $authorId === $userId;
	}
}
