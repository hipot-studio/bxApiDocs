<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateMarkupSite;
use Bitrix\Landing\Copilot\Generation\Scenario\MarkupSiteState;
use Bitrix\Landing\Copilot\Manager as CopilotManager;
use Bitrix\Landing\Integration\AiAssistant\Dto\CancelMarkupJobDto;
use Bitrix\Landing\Markup\MarkupCreationOutcome;
use Bitrix\Landing\Markup\MarkupLifecycleLog;
use Bitrix\Landing\Markup\UploadBuffer;

/**
 * Cancels an unfinished markup generation of the caller: the run is marked as failed
 * ("cancelled by the user") and the retry agent will not resume it. The upload buffer
 * is kept and its lifetime restarts: "continue" resubmits the same package.
 * Idempotent: a repeated cancel and a cancel of an already finished or failed job
 * answer {cancelled: false} without an error.
 * The answer follows Scenarist::abort(): a cancel whose Error status was not persisted
 * answers {cancelled: false}, and the job goes on.
 * A cancelled creation frees the chat without spending a moderation attempt and removes the partial
 * site - under the lock of the generation, so never under a step that is still running: while a run
 * (the queue callback, the retry agent) holds the lock, that run reads the Error mark back before it
 * writes anything of its own and ends with the terminal reaction of the scenario, which frees the
 * chat (Scenarist::isAbortedOutside()); the sweeper stays the safety net. A cancelled edit only stops.
 */
class CancelMarkupJobHandler extends AbstractSiteHandler
{
	public function handle(CancelMarkupJobDto $dto, int $userId): array
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

		if ($generation->isFinished() || $generation->isErrorPersisted())
		{
			return [
				'cancelled' => false,
				'jobId' => $dto->jobId,
				'message' => 'The markup job is not running, there is nothing to cancel.',
			];
		}

		// the abort writes the Error status itself; false means the status did not reach the
		// database, so the job keeps its executor and nothing else may be recorded or released
		if (!$this->cancelGeneration($generation))
		{
			return [
				'cancelled' => false,
				'jobId' => $dto->jobId,
				'message' => 'The markup job is still running: the cancel was not saved. Call cancel_markup_job again.',
			];
		}

		$generation->rememberCancellation();
		$state = new MarkupSiteState($generation);
		$state->setLastError(MarkupSiteState::CANCELLED_MESSAGE);
		$generation->persistState();

		$uploadId = $state->getUploadId();
		if ($uploadId !== '')
		{
			$this->getUploadBuffer()->touch($uploadId);
		}

		$outcome = $this->getCreationOutcome();
		$isReleased = $this->runUnderGenerationLock(
			$generation,
			static fn() => $outcome->onCancelled($generation),
		);
		$this->getLifecycleLog()->write(MarkupLifecycleLog::EVENT_CANCEL, [
			'jobId' => $generationId,
			'bindingId' => CreateAiSiteState::getAiSiteBindingId($generation),
			'uploadId' => $uploadId,
			'userId' => $userId,
			'reason' => $isReleased ? 'stopped' : 'release_deferred',
		]);

		return [
			'cancelled' => true,
			'jobId' => $dto->jobId,
			'message' => 'The markup job is cancelled. A new submission starts a new job.',
		];
	}

	protected function isAiSitesEnabled(): bool
	{
		return CopilotManager::isAiSitesEnabled();
	}

	protected function loadGeneration(int $generationId, int $userId): Generation
	{
		$generation = $this->initGenerationById($generationId);
		$authorId = (int)$generation?->getAuthorId();
		// one answer for an unknown, off-scenario and foreign job: the text must not tell them apart
		if (
			$generation === null
			|| !$generation->getScenario() instanceof CreateMarkupSite
			|| $authorId <= 0
			|| $authorId !== $userId
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

	protected function cancelGeneration(Generation $generation): bool
	{
		return $generation->cancel();
	}

	protected function getUploadBuffer(): UploadBuffer
	{
		return new UploadBuffer();
	}

	protected function getCreationOutcome(): MarkupCreationOutcome
	{
		return new MarkupCreationOutcome();
	}

	protected function getLifecycleLog(): MarkupLifecycleLog
	{
		return new MarkupLifecycleLog();
	}

	/**
	 * False when a run of the generation holds its lock: the operation is not run then.
	 */
	protected function runUnderGenerationLock(Generation $generation, callable $operation): bool
	{
		return $generation->runExclusively($operation);
	}

	private function parseGenerationId(string $jobId): int
	{
		if (!preg_match('/^[1-9][0-9]*$/', $jobId))
		{
			return 0;
		}

		return (int)$jobId;
	}
}
