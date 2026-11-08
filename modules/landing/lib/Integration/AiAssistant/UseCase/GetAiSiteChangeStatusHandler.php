<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Descriptor;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSite;
use Bitrix\Landing\Copilot\Manager as CopilotManager;
use Bitrix\Landing\Integration\AiAssistant\AiSiteChangeGenerationHelper;
use Bitrix\Landing\Integration\AiAssistant\AiSiteGenerationHelper;
use Bitrix\Landing\Integration\AiAssistant\Dto\FailedGenerationContextDto;
use Bitrix\Landing\Integration\AiAssistant\Dto\GetAiSiteChangeStatusDto;
use Bitrix\Landing\Metrika;

class GetAiSiteChangeStatusHandler
{
	/**
	 * Single answer for an unknown, foreign and off-scenario job.
	 *
	 * Generation ids are an autoincrement key, so telling the three apart would let a caller probe
	 * which ids exist and which scenario they run. The caller of a job of their own never sees it.
	 */
	private const MESSAGE_JOB_NOT_FOUND = 'Page change generation job was not found.';
	private const MESSAGE_FINISHED_WITHOUT_PAGE = 'Page change generation finished without final page identifier.';
	private const MESSAGE_COMPLETED = 'Page change generation completed.';
	private const MESSAGE_RUNNING = 'Page change generation is running.';
	private const WAIT_POLL_INTERVAL_SECONDS = 3;

	private int $waitingGenerationId = 0;
	private int $waitingUserId = 0;

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

		// Authorize the job on a light read before any payload is touched, so an unknown, foreign or
		// off-scenario job never reaches the heavy read. Skip a wait that has nothing left to wait
		// for: a job that finished or failed will not change any more. The payload is read once,
		// after the wait, so the status is fresh.
		$descriptor = $this->loadGenerationDescriptor($generationId, $userId);
		if ($dto->seconds > 0 && !$descriptor->isTerminal())
		{
			$this->waitingGenerationId = $generationId;
			$this->waitingUserId = $userId;
			$this->waitForSeconds($dto->seconds);
		}

		$generation = $this->loadGeneration($generationId, $userId);

		$pageId = AiSiteChangeGenerationHelper::extractPageId($generation);
		$siteId = AiSiteChangeGenerationHelper::extractSiteId($generation);
		if ($generation->isError())
		{
			// A failure before step 40 knows the page but not the site: DTO-02 wants both real.
			$siteId = $siteId > 0 ? $siteId : $this->resolveSiteIdByPageId($pageId);

			// Resolve the errored step and its blocking verdict once, as one context: the reason text
			// and the classification both need them, and each would otherwise repeat the read.
			$failedContext = FailedGenerationContextDto::forChangeGeneration($generation);

			return AiSiteChangeGenerationHelper::buildFailedResponse(
				generation: $generation,
				jobId: $dto->jobId,
				pageId: $pageId,
				siteId: $siteId,
				error: AiSiteChangeGenerationHelper::resolveFailureMessage($generation, $failedContext),
				failure: AiSiteChangeGenerationHelper::resolveFailure($generation, $failedContext),
			);
		}

		if (!$generation->isFinished())
		{
			return $this->buildRunningResponse($generation, $dto->jobId, $pageId, $siteId);
		}

		if ($pageId <= 0)
		{
			return AiSiteChangeGenerationHelper::buildFailedResponse(
				generation: $generation,
				jobId: $dto->jobId,
				pageId: $pageId,
				siteId: $siteId,
				error: self::MESSAGE_FINISHED_WITHOUT_PAGE,
				failure: AiSiteGenerationHelper::buildFailure(
					AiSiteGenerationHelper::ERROR_TYPE_TECHNICAL,
					null,
					Metrika\Statuses::Error->value,
				),
			);
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

	protected function resolveSiteIdByPageId(int $pageId): int
	{
		return AiSiteChangeGenerationHelper::resolveSiteIdByPageId($pageId);
	}

	protected function waitForSeconds(int $seconds): void
	{
		// Leave as soon as the job is over, re-reading only the light descriptor between short sleeps.
		for ($left = $seconds; $left > 0; $left -= $step)
		{
			$step = min(self::WAIT_POLL_INTERVAL_SECONDS, $left);
			$this->sleepSeconds($step);
			if ($left > $step && $this->loadGenerationDescriptor($this->waitingGenerationId, $this->waitingUserId)->isTerminal())
			{
				return;
			}
		}
	}

	protected function sleepSeconds(int $seconds): void
	{
		sleep($seconds);
	}

	protected function isAiSitesEnabled(): bool
	{
		return CopilotManager::isAiSitesEnabled();
	}

	/**
	 * Authorizes the job without reading its payload.
	 *
	 * Runs on every call, so an unknown, foreign or off-scenario job is rejected before the worker is
	 * held and before the heavy read hydrates anything. Also reports whether the job is already over —
	 * by the finish mark on the generation, or by the error mark on a step. Both marks live outside
	 * the payload this read deliberately skips.
	 *
	 * @throws \RuntimeException When the caller may not poll this job.
	 */
	protected function loadGenerationDescriptor(int $generationId, int $userId): Descriptor
	{
		$descriptor = Generation::findDescriptorById($generationId);
		if (
			$descriptor === null
			|| !$descriptor->isScenario(ChangeAiSite::class)
			|| !$descriptor->isOwnedBy($userId)
		)
		{
			throw new \RuntimeException(self::MESSAGE_JOB_NOT_FOUND);
		}

		return $descriptor;
	}

	protected function loadGeneration(int $generationId, int $userId): Generation
	{
		$generation = $this->createGenerationInstance();
		if (
			!$generation->initByIdWithoutHtmlBlocks($generationId)
			|| !$generation->getScenario() instanceof ChangeAiSite
			|| !$this->canAccessGeneration($generation, $userId)
		)
		{
			throw new \RuntimeException(self::MESSAGE_JOB_NOT_FOUND);
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
