<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSite;
use Bitrix\Landing\Copilot\Manager as CopilotManager;
use Bitrix\Landing\Integration\AiAssistant\AiSiteChangeGenerationHelper;
use Bitrix\Landing\Integration\AiAssistant\AiSiteGenerationHelper;
use Bitrix\Landing\Integration\AiAssistant\Dto\GetAiSiteChangeStatusDto;

class GetAiSiteChangeStatusHandler
{
	private const MESSAGE_JOB_NOT_FOUND = 'Page change generation job was not found.';
	private const MESSAGE_SCENARIO_MISMATCH = 'Page change generation job does not belong to the expected scenario.';
	private const MESSAGE_ACCESS_DENIED = 'Access denied for this page change generation job.';
	private const MESSAGE_FINISHED_WITHOUT_PAGE = 'Page change generation finished without final page identifier.';
	private const MESSAGE_COMPLETED = 'Page change generation completed.';
	private const MESSAGE_RUNNING = 'Page change generation is running.';

	public function handle(GetAiSiteChangeStatusDto $dto, int $userId): array
	{
		$generationId = $this->parseGenerationId($dto->jobId);
		if ($generationId <= 0)
		{
			throw new \RuntimeException(self::MESSAGE_JOB_NOT_FOUND);
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

		$pageId = AiSiteChangeGenerationHelper::extractPageId($generation);
		$siteId = AiSiteChangeGenerationHelper::extractSiteId($generation);
		if ($generation->isError())
		{
			$limitError = AiSiteGenerationHelper::extractLimitError($generation);
			if ($limitError !== null)
			{
				return AiSiteChangeGenerationHelper::buildStatusResponse(
					jobId: $dto->jobId,
					status: 'failed',
					pageId: $pageId,
					message: 'Page change generation failed.',
					error: AiSiteChangeGenerationHelper::resolveFailureMessage($generation),
					changeResult: AiSiteChangeGenerationHelper::extractResultState($generation),
					siteId: $siteId,
					limitError: $limitError,
				);
			}

			throw new \RuntimeException(AiSiteChangeGenerationHelper::resolveFailureMessage($generation));
		}

		if (!$generation->isFinished())
		{
			return $this->buildRunningResponse($generation, $dto->jobId, $pageId, $siteId);
		}

		if ($pageId <= 0)
		{
			throw new \RuntimeException(self::MESSAGE_FINISHED_WITHOUT_PAGE);
		}

		return $this->buildCompletedResponse($generation, $dto->jobId, $pageId, $siteId);
	}

	private function buildRunningResponse(Generation $generation, string $jobId, int $pageId, int $siteId): array
	{
		return AiSiteChangeGenerationHelper::buildStatusResponse(
			jobId: $jobId,
			status: 'running',
			pageId: $pageId,
			message: self::MESSAGE_RUNNING,
			changeResult: AiSiteChangeGenerationHelper::extractResultState($generation),
			siteId: $siteId,
		);
	}

	private function buildCompletedResponse(Generation $generation, string $jobId, int $pageId, int $siteId): array
	{
		return AiSiteChangeGenerationHelper::buildStatusResponse(
			jobId: $jobId,
			status: 'completed',
			pageId: $pageId,
			message: self::MESSAGE_COMPLETED,
			changeResult: AiSiteChangeGenerationHelper::extractResultState($generation),
			siteId: $siteId,
			changeSummary: AiSiteChangeGenerationHelper::extractChangeSummary($generation),
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

	protected function loadGeneration(int $generationId, int $userId): Generation
	{
		$generation = $this->createGenerationInstance();
		if (!$generation->initById($generationId))
		{
			throw new \RuntimeException(self::MESSAGE_JOB_NOT_FOUND);
		}

		if (!$generation->getScenario() instanceof ChangeAiSite)
		{
			throw new \RuntimeException(self::MESSAGE_SCENARIO_MISMATCH);
		}

		if (!$this->canAccessGeneration($generation, $userId))
		{
			throw new \RuntimeException(self::MESSAGE_ACCESS_DENIED);
		}

		return $generation;
	}

	protected function createGenerationInstance(): Generation
	{
		return new Generation();
	}

	private function parseGenerationId(string $jobId): int
	{
		if (!preg_match('/^[1-9][0-9]*$/', $jobId))
		{
			return 0;
		}

		return (int)$jobId;
	}

	private function canAccessGeneration(Generation $generation, int $userId): bool
	{
		$authorId = $generation->getAuthorId();

		return $authorId > 0 && $authorId === $userId;
	}
}
