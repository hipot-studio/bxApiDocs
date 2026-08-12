<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Copilot\Data\Site as CopilotSiteData;
use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSite;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteSelectedElement;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteState;
use Bitrix\Landing\Copilot\Manager as CopilotManager;
use Bitrix\Landing\Copilot\Services\CreateAiSiteChecker;
use Bitrix\Landing\Integration\AiAssistant\AiSiteChangeGenerationHelper;
use Bitrix\Landing\Integration\AiAssistant\AiSiteGenerationHelper;
use Bitrix\Landing\Integration\AiAssistant\Dto\AiSiteChatAvailabilityResult;
use Bitrix\Landing\Integration\AiAssistant\Dto\ChangeAiSiteDto;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteRuntimeAvailabilityGuard;
use Bitrix\Main\Loader;

class ChangeAiSiteHandler
{
	private const MESSAGE_INVALID_PAGE_ID = 'Page id is required to start page change generation.';
	private const MESSAGE_EMPTY_BRIEF = 'Brief lines are required to start page change generation.';
	private const ERROR_AI_SITE_REQUIRED = 'AI_SITE_REQUIRED';
	private const MESSAGE_AI_SITE_REQUIRED = 'AI site changes are available only for AI-created landing sites.';
	private const MESSAGE_AI_MODULE_UNAVAILABLE = 'AI module is unavailable.';
	private const MESSAGE_START_FAILED = 'Failed to start page change generation.';
	private const MESSAGE_FINISHED_WITHOUT_PAGE = 'Page change generation finished without final page identifier.';
	private const MESSAGE_COMPLETED = 'Page change generation completed.';
	private const MESSAGE_QUEUED = 'Page change generation is queued.';
	private const MESSAGE_QUEUED_FOR_RETRY = 'Page change generation is queued for retry.';
	private const MESSAGE_RUNNING = 'Page change generation is running.';

	public function handle(ChangeAiSiteDto $dto, int $userId): array
	{
		$this->validateInput($dto);

		if (!$this->isAiSitesEnabled())
		{
			throw new \RuntimeException('AI sites are disabled.');
		}

		$this->assertSitesAiProductAvailable();

		$this->assertAiCreatedLanding($dto->pageId);

		if (!$this->includeAiModule())
		{
			throw new \RuntimeException(self::MESSAGE_AI_MODULE_UNAVAILABLE);
		}

		$generation = $this->createGeneration($dto, $userId);
		$started = $generation->execute();
		$jobId = (string)($generation->getId() ?? '');
		if ($jobId === '')
		{
			throw new \RuntimeException(self::MESSAGE_START_FAILED);
		}

		return $this->buildStartResponse($generation, $jobId, $dto->pageId, $started);
	}

	private function validateInput(ChangeAiSiteDto $dto): void
	{
		if ($dto->pageId <= 0)
		{
			throw new \InvalidArgumentException(self::MESSAGE_INVALID_PAGE_ID);
		}

		if ($dto->briefLines === [])
		{
			throw new \InvalidArgumentException(self::MESSAGE_EMPTY_BRIEF);
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

	private function assertAiCreatedLanding(int $landingId): void
	{
		if ($this->isAiCreatedLanding($landingId))
		{
			return;
		}

		throw new \RuntimeException(
			self::ERROR_AI_SITE_REQUIRED . ': ' . self::MESSAGE_AI_SITE_REQUIRED,
		);
	}

	protected function isAiCreatedLanding(int $landingId): bool
	{
		return (new CreateAiSiteChecker())->isLandingCreated($landingId);
	}

	protected function createGeneration(ChangeAiSiteDto $dto, int $userId): Generation
	{
		$selectedElementContext = $this->resolveSelectedElementContext($dto->selectedElement);
		$scenario = $selectedElementContext !== []
			? new ChangeAiSiteSelectedElement()
			: new ChangeAiSite()
		;

		$generation = (new Generation())
			->setAuthorId($userId)
			->setScenario($scenario)
			->setSiteData(new CopilotSiteData())
		;
		ChangeAiSiteState::setInput($generation, [
			'landingId' => $dto->pageId,
			'input' => $dto->briefLines,
		]);

		if ($selectedElementContext !== [])
		{
			ChangeAiSiteState::setSelectedElementContext($generation, $selectedElementContext);
		}

		return $generation;
	}

	private function resolveSelectedElementContext(array $selectedElement): array
	{
		$blockId = (int)($selectedElement['blockId'] ?? 0);
		$selector = trim((string)($selectedElement['selector'] ?? ''));

		if ($blockId <= 0 || $selector === '')
		{
			return [];
		}

		return [
			'blockId' => $blockId,
			'selector' => $selector,
		];
	}

	private function buildStartResponse(Generation $generation, string $jobId, int $requestedPageId, bool $started): array
	{
		$pageId = AiSiteChangeGenerationHelper::extractPageId($generation);
		$pageId = $pageId > 0 ? $pageId : max(0, $requestedPageId);
		$siteId = AiSiteChangeGenerationHelper::extractSiteId($generation);

		if ($generation->isError())
		{
			$limitError = AiSiteGenerationHelper::extractLimitError($generation);
			if ($limitError !== null)
			{
				return AiSiteChangeGenerationHelper::buildStatusResponse(
					jobId: $jobId,
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
			if (!$started)
			{
				$step = $generation->getStep() ?? 0;
				$message = $step > 0
					? self::MESSAGE_QUEUED_FOR_RETRY
					: self::MESSAGE_QUEUED
				;

				return $this->buildRunningResponse($generation, $jobId, $pageId, $siteId, $message);
			}

			return $this->buildRunningResponse($generation, $jobId, $pageId, $siteId, self::MESSAGE_RUNNING);
		}

		if ($pageId <= 0)
		{
			throw new \RuntimeException(self::MESSAGE_FINISHED_WITHOUT_PAGE);
		}

		return $this->buildCompletedResponse($generation, $jobId, $pageId, $siteId);
	}

	private function buildRunningResponse(Generation $generation, string $jobId, int $pageId, int $siteId, string $message): array
	{
		return AiSiteChangeGenerationHelper::buildStatusResponse(
			jobId: $jobId,
			status: 'running',
			pageId: $pageId,
			message: $message,
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
}
