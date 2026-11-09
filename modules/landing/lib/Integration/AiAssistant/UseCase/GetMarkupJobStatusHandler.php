<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeMarkupSite;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateMarkupSite;
use Bitrix\Landing\Copilot\Generation\Scenario\MarkupSiteState;
use Bitrix\Landing\Copilot\Manager as CopilotManager;
use Bitrix\Landing\Integration\AiAssistant\Dto\GetMarkupJobStatusDto;

/**
 * Read-only status of a markup site generation. Deliberately side-effect free:
 * binding finalization and moderation counters belong to the submission path,
 * never to polling. The refusal codes of a failed job are read from the state of
 * the generation the same way, so polling classifies a failure without causing one.
 * A job is failed only once the Error status of its step is persisted: a mark that never
 * reached the database and the time budget exit leave the job to the retry agent.
 */
class GetMarkupJobStatusHandler extends AbstractSiteHandler
{
	use MarkupImagesAnswer;
	use MarkupStepFailureAnswer;

	private const POLL_INTERVAL_SECONDS = 2;
	private const ERROR_CODE_GENERATION_FAILED = 'MARKUP_GENERATION_FAILED';

	public function handle(GetMarkupJobStatusDto $dto, int $userId): array
	{
		$generationId = $this->parseGenerationId($dto->jobId);
		if ($generationId <= 0)
		{
			throw new \RuntimeException('Markup job was not found.');
		}

		// An accepted job follows itself, not the generation selected now: only the common gate is asked.
		if (!$this->isAiSitesEnabled())
		{
			throw new \RuntimeException('AI sites markup tools are disabled.');
		}

		$generation = $this->loadGeneration($generationId, $userId);

		$waitedSeconds = 0;
		while (
			$waitedSeconds < $dto->wait
			&& !$generation->isFinished()
			&& !$generation->isErrorPersisted()
		)
		{
			$interval = min(self::POLL_INTERVAL_SECONDS, $dto->wait - $waitedSeconds);
			$this->sleepSeconds($interval);
			$waitedSeconds += $interval;
			$generation = $this->loadGeneration($generationId, $userId);
		}

		return $this->buildStatusResponse($generation, $dto->jobId);
	}

	protected function isAiSitesEnabled(): bool
	{
		return CopilotManager::isAiSitesEnabled();
	}

	protected function sleepSeconds(int $seconds): void
	{
		sleep($seconds);
	}

	protected function loadGeneration(int $generationId, int $userId): Generation
	{
		$generation = $this->initGenerationById($generationId);
		// one answer for an unknown, off-scenario and foreign job: the text must not tell them apart
		if (
			$generation === null
			|| !$generation->getScenario() instanceof CreateMarkupSite
			|| !$this->canAccessGeneration($generation, $userId)
		)
		{
			throw new \RuntimeException('Markup job was not found.');
		}

		return $generation;
	}

	protected function initGenerationById(int $generationId): ?Generation
	{
		$generation = new Generation();

		return $generation->initById($generationId) ? $generation : null;
	}

	protected function getEditorUrl(int $siteId, int $pageId): string
	{
		return $this->getSitePageEditorUrl($siteId, $pageId);
	}

	private function canAccessGeneration(Generation $generation, int $userId): bool
	{
		$authorId = $generation->getAuthorId();

		return $authorId > 0 && $authorId === $userId;
	}

	private function parseGenerationId(string $jobId): int
	{
		if (!preg_match('/^[1-9][0-9]*$/', $jobId))
		{
			return 0;
		}

		return (int)$jobId;
	}

	private function buildStatusResponse(Generation $generation, string $jobId): array
	{
		$step = $this->resolveStepMeta($generation);
		$repairIteration = (new MarkupSiteState($generation))->getRepairIteration();

		if ($generation->isErrorPersisted())
		{
			$details = (new MarkupSiteState($generation))->getLastError();
			$details = $details !== '' ? $details : 'Markup site generation failed.';

			$response = [
				'status' => 'failed',
				'jobId' => $jobId,
				'step' => $step,
				'errors' => [[
					'code' => self::ERROR_CODE_GENERATION_FAILED,
					'path' => null,
					'line' => null,
					'details' => $details,
					'repairable' => false,
				]],
				'message' => 'Markup site generation failed.',
			];
			if ($repairIteration > 0)
			{
				$response['repairIteration'] = $repairIteration;
			}

			return array_merge($response, $this->buildStepFailureFields($generation, $details));
		}

		[$siteId, $pageId] = $this->extractFinalIds($generation);
		if ($generation->isFinished())
		{
			if ($siteId > 0 && $pageId > 0)
			{
				$isUpdate = $generation->getScenario() instanceof ChangeMarkupSite;

				return array_merge([
					'status' => 'completed',
					'jobId' => $jobId,
					'step' => $step,
					'siteId' => $siteId,
					'pageId' => $pageId,
					'editorUrl' => $this->getEditorUrl($siteId, $pageId),
					'warnings' => $this->buildWarnings($generation),
					'message' => $isUpdate
						? 'The markup package is applied as the new page version. '
							. 'The editor page updates automatically.'
						: 'Markup site is created. The user publishes it with the editor button.',
				], $this->buildImagesFields($generation));
			}

			throw new \RuntimeException('Markup site generation finished without final site identifiers.');
		}

		$response = [
			'status' => 'running',
			'jobId' => $jobId,
			'step' => $step,
			'message' => 'Markup site generation is still running. Call get_markup_job_status again with the same jobId.',
		];
		if ($repairIteration > 0)
		{
			$response['repairIteration'] = $repairIteration;
		}

		return array_merge($response, $this->buildImagesFields($generation));
	}

	private function extractFinalIds(Generation $generation): array
	{
		$siteData = $generation->getSiteData();

		return [
			(int)($siteData->getSiteId() ?? 0),
			(int)($siteData->getLandingId() ?? 0),
		];
	}
}
