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
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteBindingFinalizer;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteBindingService;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteRuntimeAvailabilityGuard;
use Bitrix\Main\Loader;

class CreateAiSiteHandler
{
	private const MODERATION_BLOCK_LIMIT_MESSAGE =
		'Too many topic moderation blocks for this draft. Please try again later with a different topic.';

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
			throw new \RuntimeException('AI sites are disabled.');
		}

		$this->assertSitesAiProductAvailable();

		$this->assertBindingCanStartGeneration($dto, $userId);

		$this->assertModerationBlockLimitNotReached($dto->bindingId, $userId);

		if (!$this->includeAiModule())
		{
			throw new \RuntimeException('AI module is unavailable.');
		}

		$generation = $this->createGeneration($dto, $userId);
		if (!$this->persistGenerationBeforeReservation($generation) || (int)($generation->getId() ?? 0) <= 0)
		{
			throw new \RuntimeException('Failed to persist site generation before binding reservation.');
		}

		if (!$this->reserveGenerationBinding($dto, $userId, $generation))
		{
			$this->discardGenerationBeforeStart($generation);

			throw new \RuntimeException(AiSiteChatBindingContract::BINDING_UNAVAILABLE_ERROR);
		}

		$generation->sendGenerationCreateEvent();

		$started = $generation->execute();
		$jobId = (string)($generation->getId() ?? '');
		if ($jobId === '')
		{
			throw new \RuntimeException('Failed to start site generation.');
		}

		return $this->buildStartResponse($generation, $jobId, $started);
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

	private function assertSitesAiProductAvailable(): void
	{
		$availability = $this->checkSitesAiProductAvailability();
		if ($availability->isAvailable())
		{
			return;
		}

		throw new \RuntimeException(
			'AI sites are unavailable: ' . $availability->getReasonCodeOrDefault('unknown'),
		);
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
			throw new \RuntimeException(AiSiteChatBindingContract::BINDING_UNAVAILABLE_ERROR);
		}
	}

	protected function assertModerationBlockLimitNotReached(int $bindingId, int $userId): void
	{
		if ($this->getAiSiteBindingService()->isModerationBlockLimitReached($bindingId, $userId))
		{
			throw new \RuntimeException(self::MODERATION_BLOCK_LIMIT_MESSAGE);
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
		if ($generation->isError())
		{
			$this->releaseDraftOnModerationBlock($generation);

			$limitError = AiSiteGenerationHelper::extractLimitError($generation);
			$creationJournal = AiSiteGenerationHelper::extractCreationJournal($generation);
			if ($limitError !== null || (!empty($creationJournal['recoverablePartial']) && (int)$creationJournal['siteId'] > 0))
			{
				$recoverablePartial = !empty($creationJournal['recoverablePartial']) && (int)$creationJournal['siteId'] > 0;

				return AiSiteGenerationHelper::buildStatusResponse(
					jobId: $jobId,
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

			throw new \RuntimeException('Site generation finished without final site identifiers.');
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
}
