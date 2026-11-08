<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Descriptor;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSite;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Manager as CopilotManager;
use Bitrix\Landing\Integration\AiAssistant\AiSiteGenerationHelper;
use Bitrix\Landing\Integration\AiAssistant\Dto\AiSiteChatAvailabilityResult;
use Bitrix\Landing\Integration\AiAssistant\Dto\FailedGenerationContextDto;
use Bitrix\Landing\Integration\AiAssistant\Dto\GetAiSiteStatusDto;
use Bitrix\Landing\Integration\AiAssistant\Exception\AiSitesDisabledException;
use Bitrix\Landing\Integration\AiAssistant\Exception\AiSitesUnavailableException;
use Bitrix\Landing\Integration\AiAssistant\Exception\BindingFinalizationException;
use Bitrix\Landing\Integration\AiAssistant\Exception\GenerationAccessDeniedException;
use Bitrix\Landing\Integration\AiAssistant\Exception\GenerationNotFoundException;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteBindingFinalizer;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteBindingService;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteFailedGenerationReaction;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteRuntimeAvailabilityGuard;
use Bitrix\Landing\Metrika;

class GetAiSiteStatusHandler
{
	/**
	 * Single answer for an unknown, foreign and off-scenario job.
	 *
	 * Generation ids are an autoincrement key, so telling the three apart would let a caller probe
	 * which ids exist and which scenario they run. The caller of a job of their own never sees it.
	 *
	 * The refusals stay typed apart for the callers that map them (REST answers all three with the
	 * same 404); only the wording is shared, and GenerationAccessDeniedException carries it too.
	 */
	private const MESSAGE_JOB_NOT_FOUND = GenerationNotFoundException::MESSAGE;
	private const MESSAGE_FINISHED_WITHOUT_IDS = 'Site generation finished without final site identifiers.';
	private const WAIT_POLL_INTERVAL_SECONDS = 3;

	private int $waitingGenerationId = 0;
	private int $waitingUserId = 0;

	public function handle(GetAiSiteStatusDto $dto, int $userId): array
	{
		$generationId = $this->parseGenerationId($dto->jobId);
		if ($generationId <= 0)
		{
			throw new GenerationNotFoundException(self::MESSAGE_JOB_NOT_FOUND);
		}

		// the release option is asked first on purpose: the product gate below reads the same option
		// too, but answers it as one availability reason among several, and the option deserves its
		// own refusal. Its branch inside the gate is therefore unreachable from here.
		if (!$this->isAiSitesEnabled())
		{
			throw new AiSitesDisabledException();
		}

		$this->assertSitesAiStatusReadAvailable();

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

		if ($generation->isError())
		{
			// Resolve the errored step and its blocking verdict once, as one context: the reaction, the
			// reason text and the classification all need them, and each would otherwise repeat the read.
			$failedContext = FailedGenerationContextDto::forCreateGeneration($generation);

			$this->reactToFailedGeneration($generation, $failedContext->erroredStepId);

			return AiSiteGenerationHelper::buildFailedResponse(
				generation: $generation,
				jobId: $dto->jobId,
				error: AiSiteGenerationHelper::resolveFailureMessage($generation, $failedContext),
				failure: AiSiteGenerationHelper::resolveFailure($generation, $failedContext),
			);
		}

		[$siteId, $pageId] = AiSiteGenerationHelper::extractFinalIds($generation);
		if ($generation->isFinished())
		{
			if ($siteId > 0 && $pageId > 0)
			{
				if (!$this->finalizeCompletedGenerationBinding($generation))
				{
					throw new BindingFinalizationException();
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

			// A generation that finished without final identifiers is a terminal failure with nothing
			// built, so it reacts on the binding the same way the errored branch does: the reservation of
			// a chat left without a draft is freed instead of staying occupied by a generation that will
			// never finish. No errored step is known here - the reaction resolves it on its own.
			$this->reactToFailedGeneration($generation);

			return AiSiteGenerationHelper::buildFailedResponse(
				generation: $generation,
				jobId: $dto->jobId,
				error: self::MESSAGE_FINISHED_WITHOUT_IDS,
				failure: AiSiteGenerationHelper::buildFailure(
					AiSiteGenerationHelper::ERROR_TYPE_TECHNICAL,
					null,
					Metrika\Statuses::Error->value,
				),
			);
		}

		return AiSiteGenerationHelper::buildStatusResponse(
			jobId: $dto->jobId,
			status: 'running',
			message: 'Site is still generating. Call get_ai_site_status again with the same jobId as the skill polling schedule says. Site generation can take several minutes.',
		);
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
	 * The product gate of the read-only slice: the same one the chat tools apply through
	 * AiSiteRuntimeAvailabilityToolGate, without the tariff and tuning gates that only guard a start.
	 *
	 * It lives here rather than in the tool gate alone because the tool gate is not on the path of
	 * every entry point any more: landing.aisite.status reaches this handler directly, and a portal
	 * the AI site product is closed for must answer the same way to both.
	 */
	protected function assertSitesAiStatusReadAvailable(): void
	{
		$availability = $this->checkSitesAiStatusReadAvailability();
		if ($availability->isAvailable())
		{
			return;
		}

		throw new AiSitesUnavailableException($availability->getReasonCodeOrDefault('unknown'));
	}

	protected function checkSitesAiStatusReadAvailability(): AiSiteChatAvailabilityResult
	{
		return $this->getAiSiteRuntimeAvailabilityGuard()->checkStatusReadProductAvailability();
	}

	protected function getAiSiteRuntimeAvailabilityGuard(): AiSiteRuntimeAvailabilityGuard
	{
		return new AiSiteRuntimeAvailabilityGuard();
	}

	private function parseGenerationId(string $jobId): int
	{
		if (!preg_match('/^[1-9][0-9]*$/', $jobId))
		{
			return 0;
		}

		return (int)$jobId;
	}

	/**
	 * Authorizes the job without reading its payload. This is the only authorization of the path.
	 *
	 * Runs on every call, so an unknown, foreign or off-scenario job is rejected before the worker is
	 * held and before the heavy read hydrates anything. Also reports whether the job is already over —
	 * by the finish mark on the generation, or by the error mark on a step. Both marks live outside
	 * the payload this read deliberately skips.
	 *
	 * @throws GenerationNotFoundException When the caller may not poll this job.
	 */
	protected function loadGenerationDescriptor(int $generationId, int $userId): Descriptor
	{
		$descriptor = Generation::findDescriptorById($generationId);
		if (
			$descriptor === null
			|| !$descriptor->isScenario(CreateAiSite::class)
			|| !$descriptor->isOwnedBy($userId)
		)
		{
			throw new GenerationNotFoundException(self::MESSAGE_JOB_NOT_FOUND);
		}

		return $descriptor;
	}

	/**
	 * Reads the payload needed to describe the status, once the descriptor has authorized the job.
	 *
	 * Narrow on purpose: this path never asks for the html blocks, and the field is heavy exactly
	 * while the client polls. The identity checks here are not the authorization — that already
	 * happened on the descriptor — but a guard against the row changing between the two reads, so
	 * they answer with the same single message.
	 *
	 * @throws GenerationNotFoundException|GenerationAccessDeniedException When the caller may not read this job.
	 */
	protected function loadGeneration(int $generationId, int $userId): Generation
	{
		$generation = new Generation();
		if (
			!$generation->initByIdWithoutHtmlBlocks($generationId)
			|| !$generation->getScenario() instanceof CreateAiSite
		)
		{
			throw new GenerationNotFoundException(self::MESSAGE_JOB_NOT_FOUND);
		}

		if (!$this->canAccessGeneration($generation, $userId))
		{
			throw new GenerationAccessDeniedException();
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
	 * Built on the binding seam above on purpose: the reaction writes through the same service the
	 * doubles of this handler substitute, so the substitution point stays the one they know.
	 */
	protected function getAiSiteFailedGenerationReaction(): AiSiteFailedGenerationReaction
	{
		return new AiSiteFailedGenerationReaction($this->getAiSiteBindingService());
	}

	/**
	 * Binding reaction to a terminally failed generation, run before the failed answer is built.
	 *
	 * The decision itself belongs to AiSiteFailedGenerationReaction, shared with the start handler:
	 * both entry points answer the same failure and must react to it the same way. The recoverable-draft
	 * verdict handed to it is the one AiSiteGenerationHelper::hasRecoverableDraft() gives the failed
	 * answer as well, so the reservation is kept exactly for the draft the agent is told about.
	 *
	 * The errored step comes already resolved from the caller of the failed branch; a caller without
	 * one passes null, and the reaction resolves it on its own.
	 */
	protected function reactToFailedGeneration(Generation $generation, ?int $erroredStepId = null): void
	{
		$this->getAiSiteFailedGenerationReaction()->react(
			$generation,
			$erroredStepId,
			AiSiteGenerationHelper::hasRecoverableDraft(
				AiSiteGenerationHelper::extractCreationJournal($generation),
			),
		);
	}

	private function canAccessGeneration(Generation $generation, int $userId): bool
	{
		$authorId = $generation->getAuthorId();

		return $authorId > 0 && $authorId === $userId;
	}
}
