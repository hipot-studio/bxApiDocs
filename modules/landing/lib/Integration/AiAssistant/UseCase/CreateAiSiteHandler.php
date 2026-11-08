<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Copilot\Data\Site as CopilotSiteData;
use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSite;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Manager as CopilotManager;
use Bitrix\Landing\Integration\AiAssistant\AiSiteGenerationHelper;
use Bitrix\Landing\Integration\AiAssistant\Contract\AiSiteChatBindingContract;
use Bitrix\Landing\Integration\AiAssistant\Dto\AiSiteChatAvailabilityResult;
use Bitrix\Landing\Integration\AiAssistant\Dto\CreateAiSiteDto;
use Bitrix\Landing\Integration\AiAssistant\Dto\FailedGenerationContextDto;
use Bitrix\Landing\Integration\AiAssistant\Exception\AiModuleUnavailableException;
use Bitrix\Landing\Integration\AiAssistant\Exception\AiSitesDisabledException;
use Bitrix\Landing\Integration\AiAssistant\Exception\AiSitesUnavailableException;
use Bitrix\Landing\Integration\AiAssistant\Exception\BindingFinalizationException;
use Bitrix\Landing\Integration\AiAssistant\Exception\BindingUnavailableException;
use Bitrix\Landing\Integration\AiAssistant\Exception\ModerationBlockLimitException;
use Bitrix\Landing\Integration\AiAssistant\Exception\SiteCreateAccessDeniedException;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteBindingFinalizer;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteBindingService;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteFailedGenerationReaction;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteRuntimeAvailabilityGuard;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Rights;
use Bitrix\Landing\Site\Type;
use Bitrix\Landing\Metrika;
use Bitrix\Main\Loader;

class CreateAiSiteHandler
{
	private const ERROR_MODERATION_BLOCK_LIMIT = 'MODERATION_BLOCK_LIMIT';
	private const MODERATION_BLOCK_LIMIT_MESSAGE =
		'Too many topic moderation blocks for this draft. Generation from this draft is paused for '
		. AiSiteBindingService::MODERATION_BLOCK_COOLDOWN_HOURS
		. ' hours since the last block; please try again after that with a different topic.';
	private const MESSAGE_FINISHED_WITHOUT_IDS = 'Site generation finished without final site identifiers.';

	public function handle(CreateAiSiteDto $dto, int $userId): array
	{
		if ($dto->briefLines === [])
		{
			throw new \InvalidArgumentException('Brief lines are required to start site generation.');
		}

		if ($dto->bindingId <= 0)
		{
			throw new \InvalidArgumentException(AiSiteChatBindingContract::BINDING_REQUIRED_ERROR);
		}

		if (!$this->isAiSitesEnabled())
		{
			throw new AiSitesDisabledException();
		}

		$this->assertUserCanCreateSite($userId);

		$this->assertAiModuleAvailable();

		$this->assertSitesAiProductAvailable();

		$this->assertBindingCanStartGeneration($dto, $userId);

		$this->assertModerationBlockLimitNotReached($dto->bindingId, $userId);

		$generation = $this->createGeneration($dto, $userId);
		if (!$this->persistGenerationBeforeReservation($generation) || (int)($generation->getId() ?? 0) <= 0)
		{
			throw new \RuntimeException('Failed to persist site generation before binding reservation.');
		}

		if (!$this->reserveGenerationBinding($dto, $userId, $generation))
		{
			$this->discardGenerationBeforeStart($generation);

			throw new BindingUnavailableException(AiSiteChatBindingContract::BINDING_UNAVAILABLE_ERROR);
		}

		$generation->sendGenerationCreateEvent();

		try
		{
			$started = $generation->execute();
		}
		catch (\Throwable $executionFailure)
		{
			$this->releaseBindingOfTerminalGeneration($generation);

			throw $executionFailure;
		}

		$jobId = (string)($generation->getId() ?? '');
		if ($jobId === '')
		{
			throw new \RuntimeException('Failed to start site generation.');
		}

		return $this->buildStartResponse($generation, $jobId, $started);
	}

	/**
	 * Checks the release option AI sites are switched off by.
	 *
	 * Public on purpose, the same way assertUserCanCreateSite() is: a caller with side effects of
	 * its own (creating a free draft binding, for one) asks it before them, so that a call which is
	 * going to be refused is refused first and names the reason every other entry point names. The
	 * gate inside handle() stays the guarantee.
	 */
	public function assertAiSitesEnabled(): void
	{
		if (!$this->isAiSitesEnabled())
		{
			throw new AiSitesDisabledException();
		}
	}

	/**
	 * Checks the landing `create` right of the user the generation is started for.
	 *
	 * Public on purpose: a caller that has to refuse before its own side effects (creating a free
	 * draft binding, for one) calls it as a preflight, while the call inside handle() stays the
	 * guarantee. The rule itself lives here only, callers never reimplement it. The free draft is
	 * not what the preflight guards: getOrCreateFreeDraft() does not occupy the draft, and the
	 * reservation happens later inside handle() - after this very check.
	 *
	 * Trustworthiness precondition, checked by assertUserIsCurrentUser(): Rights::hasAdditionalRight()
	 * does not read the context user in every branch - Manager::isAdmin() reads $GLOBALS['USER'] and
	 * extra rights read the CRM rights of the context user. The answer is therefore trustworthy when
	 * $userId is the currently authenticated user, or when the caller has already substituted the
	 * whole user context the way BaseTool::runInUserContext() does.
	 *
	 * Second precondition: call it outside a section. The base section is pinned for the check,
	 * because an AI site is created with TYPE = 'PAGE' and belongs there, while the right is section
	 * scoped and a section left over from the caller would answer for that other section. Pinning it
	 * means clearing it: hasAdditionalRight() reads the entered section when given none, so there is
	 * no way to name the base one. No entry point of today enters a section before this call, so no
	 * caller is answered differently than it was before the check moved here.
	 *
	 * What is restored afterwards is the section id alone: setScope() is re-entered without the
	 * params it was originally given, and rolls back to no section at all when init() refuses to
	 * enter. A section entered by the caller therefore does not survive this check unchanged.
	 */
	public function assertUserCanCreateSite(int $userId): void
	{
		$this->assertUserIsCurrentUser($userId);

		$previousScopeId = Type::getCurrentScopeId();
		$previousRightsUserId = Rights::getContextUserId();

		try
		{
			Rights::setContextUserId($userId);
			Type::clearScope();

			$allowed = Rights::hasAdditionalRight(Rights::ADDITIONAL_RIGHTS['create']);
		}
		finally
		{
			if ($previousScopeId !== null)
			{
				Type::setScope($previousScopeId);
			}

			Rights::setContextUserId($previousRightsUserId);
		}

		if (!$allowed)
		{
			throw new SiteCreateAccessDeniedException();
		}
	}

	/**
	 * Keeps the answer of the right check from being collected for two different users: the branches
	 * that read the global user would answer "am I allowed" instead of "is he allowed". A mismatch is
	 * a caller error, not a refusal, and must not be answered with SiteCreateAccessDeniedException.
	 */
	private function assertUserIsCurrentUser(int $userId): void
	{
		$currentUserId = $this->getCurrentUserId();
		if ($userId !== $currentUserId)
		{
			throw new \InvalidArgumentException(
				'The landing create right is only checkable for the current user context,'
				. " asked for {$userId} while the current user is {$currentUserId}.",
			);
		}
	}

	/** The very source the global branches of the right check read. */
	protected function getCurrentUserId(): int
	{
		return Manager::getUserId();
	}

	/**
	 * Checks the ai module the generation runs on, and has to run before the product gate: the
	 * product availability chain (CopilotManager::isAvailable()) includes the ai module itself, so
	 * asked after it this refusal is unreachable - a portal without the module would answer with the
	 * product gate refusal instead of naming the outage.
	 *
	 * Public on purpose, the same way the other asserts of handle() are: a caller with side effects
	 * of its own asks it as a preflight in the same order, and the call inside handle() stays the
	 * guarantee.
	 */
	public function assertAiModuleAvailable(): void
	{
		if (!$this->includeAiModule())
		{
			throw new AiModuleUnavailableException();
		}
	}

	protected function includeAiModule(): bool
	{
		return Loader::includeModule('ai');
	}

	protected function isAiSitesEnabled(): bool
	{
		return CopilotManager::isAiSitesEnabled();
	}

	protected function checkSitesAiProductAvailability(): AiSiteChatAvailabilityResult
	{
		return $this->getAiSiteRuntimeAvailabilityGuard()->checkProductAvailability();
	}

	protected function getAiSiteRuntimeAvailabilityGuard(): AiSiteRuntimeAvailabilityGuard
	{
		return new AiSiteRuntimeAvailabilityGuard();
	}

	/**
	 * Checks the product gate of AI sites: the tariff and the tuning of Copilot, on top of the
	 * release option.
	 *
	 * Public on purpose, the same way assertAiSitesEnabled() and assertUserCanCreateSite() are: a
	 * caller with side effects of its own (creating a free draft binding, for one) asks it before
	 * them, so that a call which is going to be refused leaves nothing behind. The gate inside
	 * handle() stays the guarantee.
	 */
	public function assertSitesAiProductAvailable(): void
	{
		$availability = $this->checkSitesAiProductAvailability();
		if ($availability->isAvailable())
		{
			return;
		}

		throw new AiSitesUnavailableException($availability->getReasonCodeOrDefault('unknown'));
	}

	protected function createGeneration(CreateAiSiteDto $dto, int $userId): Generation
	{
		$generation = (new Generation())
			->setAuthorId($userId)
			->setScenario(new CreateAiSite())
			->setSiteData(new CopilotSiteData())
		;

		$input = [
			'input' => $dto->briefLines,
			AiSiteChatBindingContract::TOOL_BINDING_ID_FIELD => $dto->bindingId,
		];

		CreateAiSiteState::setInput($generation, $input);
		CreateAiSiteState::setAiSiteBindingId($generation, $dto->bindingId);

		return $generation;
	}

	protected function assertBindingCanStartGeneration(CreateAiSiteDto $dto, int $userId): void
	{
		if (!$this->getAiSiteBindingService()->isFreeDraftForGeneration($dto->bindingId, $userId))
		{
			throw new BindingUnavailableException(AiSiteChatBindingContract::BINDING_UNAVAILABLE_ERROR);
		}
	}

	protected function assertModerationBlockLimitNotReached(int $bindingId, int $userId): void
	{
		if ($this->getAiSiteBindingService()->isModerationBlockLimitReached($bindingId, $userId))
		{
			// The cooldown window is spent by the first start after it expires, not by the first success.
			if ($this->getAiSiteBindingService()->resetModerationBlockCountAfterCooldown($bindingId, $userId))
			{
				return;
			}

			throw new ModerationBlockLimitException(
				self::ERROR_MODERATION_BLOCK_LIMIT . ': ' . self::MODERATION_BLOCK_LIMIT_MESSAGE,
			);
		}
	}

	protected function persistGenerationBeforeReservation(Generation $generation): bool
	{
		return $generation->persistBeforeStart(false);
	}

	protected function reserveGenerationBinding(CreateAiSiteDto $dto, int $userId, Generation $generation): bool
	{
		return $this->getAiSiteBindingService()->reserveDraftForGeneration(
			$dto->bindingId,
			$userId,
			(int)($generation->getId() ?? 0),
		);
	}

	protected function discardGenerationBeforeStart(Generation $generation): bool
	{
		return $generation->discardBeforeStart();
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

	private function buildStartResponse(Generation $generation, string $jobId, bool $started): array
	{
		// The terminal answer and the release of the reservation are decided by the same predicate the
		// run itself decides the agent by: an Error mark that never reached the database leaves the step
		// executable, so the generation keeps an executor and is going to continue. Answering it as
		// failed and freeing the reservation would end a generation that is still running.
		if ($generation->isErrorPersisted())
		{
			// Resolve the errored step and its blocking verdict once, as one context: the reaction, the
			// reason text and the classification all need them, and each would otherwise repeat the read.
			$failedContext = FailedGenerationContextDto::forCreateGeneration($generation);

			$this->reactToFailedGeneration($generation, $failedContext->erroredStepId);

			return AiSiteGenerationHelper::buildFailedResponse(
				generation: $generation,
				jobId: $jobId,
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
					jobId: $jobId,
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
				jobId: $jobId,
				error: self::MESSAGE_FINISHED_WITHOUT_IDS,
				failure: AiSiteGenerationHelper::buildFailure(
					AiSiteGenerationHelper::ERROR_TYPE_TECHNICAL,
					null,
					Metrika\Statuses::Error->value,
				),
			);
		}

		if (!$started)
		{
			$step = $generation->getStep() ?? 0;
			$message = $step > 0
				? 'Site generation is queued for retry.'
				: 'Site generation is queued.'
			;

			return AiSiteGenerationHelper::buildStatusResponse(
				jobId: $jobId,
				status: 'running',
				message: $message,
			);
		}

		return AiSiteGenerationHelper::buildStatusResponse(
			jobId: $jobId,
			status: 'running',
			message: 'Site generation is running.',
		);
	}

	/**
	 * Same reaction as the failed branch of the answer, for a run that never reached that branch.
	 *
	 * A \Throwable that is not an Exception travels out of the generation instead of being answered,
	 * so buildStartResponse() is never entered and the reservation of the chat is left occupied. The
	 * client gets no jobId either, so the poll of the status - the only other place the reservation is
	 * freed at - is out of reach as well, and the reservation has neither a lifetime nor a sweeper: the
	 * chat would refuse every later generation for good. The reservation therefore follows the fact of
	 * a terminal failure and not the way the run left the handler.
	 *
	 * The answer of such a run is deliberately left as it is - the exception travels on unchanged, and
	 * a reaction that fails on its own must not take its place.
	 */
	private function releaseBindingOfTerminalGeneration(Generation $generation): void
	{
		try
		{
			if ($generation->isErrorPersisted())
			{
				$this->reactToFailedGeneration($generation);
			}
		}
		catch (\Throwable)
		{
			// the failure of the run is the one that must reach the caller
		}
	}

	/**
	 * Binding reaction to a terminally failed generation, run before the failed answer is built.
	 *
	 * The decision itself belongs to AiSiteFailedGenerationReaction, shared with the status handler:
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
}
