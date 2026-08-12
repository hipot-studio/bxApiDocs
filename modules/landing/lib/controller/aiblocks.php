<?php

namespace Bitrix\Landing\Controller;

use Bitrix\Landing\AI\SiteBuilder\Dto\DevContextDto;
use Bitrix\Landing\AI\SiteBuilder\Html\AiBlockHtmlPayloadProcessor;
use Bitrix\Landing\AI\SiteBuilder\Service\DevContextService;
use Bitrix\Landing\AI\SiteBuilder\Tailwind\TailwindRuntimeEligibilityService;
use Bitrix\Landing\AI\SiteBuilder\Tailwind\TailwindRuntimeInitService;
use Bitrix\Landing\Copilot\Data\Site as CopilotSiteData;
use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSite;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteErrorMessages;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteState;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSite;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Generation\Scenario\IScenario;
use Bitrix\Landing\Copilot\Generation\Scenario\IStepMetaProvider;
use Bitrix\Landing\Copilot\Services\AiSiteDevBypassService;
use Bitrix\Landing\Copilot\Generation\Type\StepStatuses;
use Bitrix\Landing\Copilot\Model\StepsTable;
use Bitrix\Landing\Integration\AiAssistant\Contract\AiSiteChatBindingContract;
use Bitrix\Landing\Integration\AiAssistant\Dto\CreateAiSiteDto;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteBindingFinalizer;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteBindingService;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteChatAvailabilityService;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteRuntimeAvailabilityGuard;
use Bitrix\Landing\Integration\AiAssistant\UseCase\CreateAiSiteHandler;
use Bitrix\Landing\PublicAction\AiBlock;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Rights;
use Bitrix\Main\Engine;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;

class AiBlocks extends Controller
{
	public function getDefaultPreFilters(): array
	{
		return [
			new Engine\ActionFilter\Authentication(),
			new ActionFilter\Extranet(),
		];
	}

	public function convertAction(array $payload = []): ?array
	{
		$contextService = $this->getContextService();
		$context = $contextService->ensureExisting();
		$landingId = $context->getLandingId();
		if ($landingId <= 0)
		{
			$this->addError(new Error('Landing ID is missing.', 'LANDING_MISSING'));

			return null;
		}

		$name = trim((string)($payload['name'] ?? ''));
		$html = $this->normalizeHtmlForConversion((string)($payload['html'] ?? ''));
		if ($html === '')
		{
			$this->addError(new Error('HTML markup is missing.', 'HTML_MISSING'));

			return null;
		}
		if (!$this->initializeTailwindRuntime($landingId))
		{
			return null;
		}

		$templateResult = AiBlock::createTemplate($html, [
			'name' => $name !== '' ? $name : 'AI block',
			'sections' => ['ai'],
		]);
		if (!$templateResult->isSuccess())
		{
			$this->addError(new Error(
				'Failed to create template.',
				'TEMPLATE_FAILED'
			));

			return null;
		}

		$templateData = $templateResult->getResult();
		$repoId = (int)($templateData['repoId'] ?? 0);
		$addResult = AiBlock::addToLanding($landingId, $repoId, 0);
		if (!$addResult->isSuccess())
		{
			$this->addError(new Error(
				'Failed to add block to page.',
				'ADD_FAILED'
			));

			return null;
		}

		return [
			'success' => true,
			'blockId' => (int)($addResult->getResult()['blockId'] ?? 0),
		];
	}

	public function magicSiteStartAction(array $payload = []): ?array
	{
		if (!$this->requireAiSitesRuntimeAvailable($payload))
		{
			return null;
		}

		if (!Rights::hasAdditionalRight(Rights::ADDITIONAL_RIGHTS['create']))
		{
			$this->addError(new Error('Insufficient permissions to create landing sites.', 'ACCESS_DENIED'));

			return null;
		}

		$inputLines = CreateAiSiteState::normalizeInputLines($payload['input'] ?? null);
		if ($inputLines === [])
		{
			$this->addError(new Error('Describe the site you want to create.', 'INPUT_MISSING'));

			return null;
		}

		if ($this->isDevAiSitesBypassRequested($payload))
		{
			return $this->startMagicSiteDevBypassGeneration($payload, $inputLines);
		}

		return $this->startMagicSiteTrustedGeneration($inputLines);
	}

	private function startMagicSiteDevBypassGeneration(array $payload, array $inputLines): ?array
	{
		if (!$this->isDevAiSitesBypassRequested($payload))
		{
			$this->addError(new Error('Dev bypass mode is not enabled for this request.', 'AI_SITES_DEV_BYPASS_REQUIRED'));

			return null;
		}

		$generation = (new Generation())
			->setScenario(new CreateAiSite())
			->setSiteData(new CopilotSiteData())
		;
		AiSiteDevBypassService::markGeneration($generation);
		$generation->setData(CreateAiSiteState::INPUT_KEY, [
			'input' => $inputLines,
		]);

		$started = $generation->execute();
		$generationId = (int)($generation->getId() ?? 0);
		if ($generationId <= 0)
		{
			$this->addError(new Error('Failed to start CreateAiSite scenario.', 'GENERATION_START_FAILED'));

			return null;
		}

		return $this->buildMagicSiteResponse($generation, [
			'started' => $started,
			'devBypass' => true,
		]);
	}

	private function startMagicSiteTrustedGeneration(array $inputLines): ?array
	{
		try
		{
			$userId = Manager::getUserId();
			$bindingId = (new AiSiteBindingService())->getOrCreateFreeDraft($userId);
			$response = (new CreateAiSiteHandler())->handle(
				CreateAiSiteDto::fromArray([
					'briefLines' => $inputLines,
					AiSiteChatBindingContract::TOOL_BINDING_ID_FIELD => $bindingId,
				]),
				$userId,
			);
		}
		catch (\Throwable $exception)
		{
			$this->addError(new Error($exception->getMessage(), 'GENERATION_START_FAILED'));

			return null;
		}

		$generationId = (int)($response['jobId'] ?? 0);
		$generation = $this->loadCreateAiSiteGeneration($generationId);
		if ($generation === null)
		{
			return null;
		}

		return $this->buildMagicSiteResponse($generation, [
			'started' => true,
		]);
	}

	public function magicSiteStatusAction(array $payload = []): ?array
	{
		if (!$this->requireAiSitesEnabled($payload))
		{
			return null;
		}

		$generationId = (int)($payload['generationId'] ?? 0);
		$generation = $this->loadCreateAiSiteGeneration($generationId);
		if ($generation === null)
		{
			return null;
		}

		return $this->buildMagicSiteResponse($generation);
	}

	public function magicSiteResumeAction(array $payload = []): ?array
	{
		if (!$this->requireAiSitesRuntimeAvailable($payload))
		{
			return null;
		}

		$generationId = (int)($payload['generationId'] ?? 0);
		$generation = $this->loadCreateAiSiteGeneration($generationId);
		if ($generation === null)
		{
			return null;
		}

		$resumed = $generation->clearErrors()->execute();

		return $this->buildMagicSiteResponse($generation, [
			'resumed' => $resumed,
		]);
	}

	public function changeAiSiteStartAction(array $payload = []): ?array
	{
		if (!$this->requireAiSitesRuntimeAvailable($payload))
		{
			return null;
		}

		$landingId = (int)($payload['landingId'] ?? 0);
		if ($landingId <= 0)
		{
			$this->addError(new Error('Specify the page ID to update.', 'LANDING_ID_MISSING'));

			return null;
		}

		$inputLines = ChangeAiSiteState::normalizeInputLines($payload['input'] ?? null);
		if ($inputLines === [])
		{
			$this->addError(new Error('Describe what should be changed on the page.', 'INPUT_MISSING'));

			return null;
		}

		if (!Rights::hasAccessForLanding($landingId, Rights::ACCESS_TYPES['edit']))
		{
			$this->addError(new Error('Insufficient permissions to edit page.', 'ACCESS_DENIED'));

			return null;
		}

		$landing = \Bitrix\Landing\Landing::createInstance($landingId, [
			'check_permissions' => false,
		]);
		if (!$landing->exist())
		{
			$this->addError(new Error('Page with the specified landingId was not found.', 'LANDING_NOT_FOUND'));

			return null;
		}
		if (!$this->isDevAiSitesBypassRequested($payload) && !$this->ensureAiSiteRuntimeSupported($landingId))
		{
			return null;
		}

		$generation = (new Generation())
			->setScenario(new ChangeAiSite())
			->setSiteData(new CopilotSiteData())
		;
		if ($this->isDevAiSitesBypassRequested($payload))
		{
			AiSiteDevBypassService::markGeneration($generation);
		}
		ChangeAiSiteState::setInput($generation, [
			'landingId' => $landingId,
			'input' => $inputLines,
		]);

		$started = $generation->execute();
		$generationId = (int)($generation->getId() ?? 0);
		if ($generationId <= 0)
		{
			$this->addError(new Error('Failed to start ChangeAiSite scenario.', 'GENERATION_START_FAILED'));

			return null;
		}

		return $this->buildChangeAiSiteResponse($generation, [
			'started' => $started,
		]);
	}

	public function changeAiSiteStatusAction(array $payload = []): ?array
	{
		if (!$this->requireAiSitesEnabled($payload))
		{
			return null;
		}

		$generationId = (int)($payload['generationId'] ?? 0);
		$generation = $this->loadChangeAiSiteGeneration($generationId);
		if ($generation === null)
		{
			return null;
		}

		return $this->buildChangeAiSiteResponse($generation);
	}

	public function changeAiSiteResumeAction(array $payload = []): ?array
	{
		if (!$this->requireAiSitesRuntimeAvailable($payload))
		{
			return null;
		}

		$generationId = (int)($payload['generationId'] ?? 0);
		$generation = $this->loadChangeAiSiteGeneration($generationId);
		if ($generation === null)
		{
			return null;
		}
		if (!$this->isDevAiSitesBypassRequested($payload) && !$this->ensureAiSiteRuntimeSupported(ChangeAiSiteState::resolveLandingId($generation)))
		{
			return null;
		}

		$resumed = $generation->clearErrors()->execute();

		return $this->buildChangeAiSiteResponse($generation, [
			'resumed' => $resumed,
		]);
	}

	private function requireAiSitesEnabled(array $payload = []): bool
	{
		if ($this->isDevAiSitesBypassRequested($payload))
		{
			return $this->requireDevAiSitesBypassAllowed();
		}

		if (\Bitrix\Landing\Copilot\Manager::isAiSitesEnabled())
		{
			return true;
		}

		$this->addError(new Error('AI sites are disabled.', 'AI_SITES_DISABLED'));

		return false;
	}

	private function requireAiSitesRuntimeAvailable(array $payload = []): bool
	{
		$guard = new AiSiteRuntimeAvailabilityGuard();
		if ($this->isDevAiSitesBypassRequested($payload))
		{
			if (!$this->requireDevAiSitesBypassAllowed())
			{
				return false;
			}

			$availability = $guard->checkAiModuleAvailability();
			if ($availability->isAvailable())
			{
				return true;
			}

			$reasonCode = $availability->getReasonCodeOrDefault('unknown');
			$this->addError(new Error(
				'AI sites dev bypass runtime is unavailable: ' . $reasonCode . '.',
				$this->mapAiSitesRuntimeErrorCode($reasonCode),
			));

			return false;
		}

		$availability = $guard->checkRuntimeAvailability();
		if ($availability->isAvailable())
		{
			return true;
		}

		$reasonCode = $availability->getReasonCodeOrDefault('unknown');
		$this->addError(new Error(
			'AI sites runtime is unavailable: ' . $reasonCode . '.',
			$this->mapAiSitesRuntimeErrorCode($reasonCode),
		));

		return false;
	}

	private function isDevAiSitesBypassRequested(array $payload): bool
	{
		return AiSiteDevBypassService::isRequestPayloadEnabled($payload);
	}

	private function requireDevAiSitesBypassAllowed(): bool
	{
		if ($this->isDevAiSitesBypassAllowed())
		{
			return true;
		}

		$this->addError(new Error(
			'AI sites dev bypass is disabled or unavailable for the current user.',
			'AI_SITES_DEV_BYPASS_FORBIDDEN',
		));

		return false;
	}

	private function isDevAiSitesBypassAllowed(): bool
	{
		return AiSiteDevBypassService::isPortalBypassAllowedForCurrentUser();
	}

	private function mapAiSitesRuntimeErrorCode(string $reasonCode): string
	{
		return match ($reasonCode)
		{
			AiSiteChatAvailabilityService::ERROR_FEATURE_DISABLED_BY_OPTION => 'AI_SITES_DISABLED',
			AiSiteChatAvailabilityService::ERROR_PRODUCT_UNAVAILABLE => 'AI_SITES_PRODUCT_UNAVAILABLE',
			AiSiteChatAvailabilityService::ERROR_FEATURE_LIMITED => 'AI_SITES_FEATURE_LIMITED',
			AiSiteChatAvailabilityService::ERROR_FEATURE_INACTIVE => 'AI_SITES_FEATURE_INACTIVE',
			AiSiteRuntimeAvailabilityGuard::ERROR_AI_MODULE_MISSING => 'AI_MODULE_MISSING',
			default => 'AI_SITES_UNAVAILABLE',
		};
	}

	private function normalizeHtmlForConversion(string $html): string
	{
		return AiBlockHtmlPayloadProcessor::sanitizeHtml($html);
	}

	private function initializeTailwindRuntime(int $landingId): bool
	{
		$runtimeInitResult = (new TailwindRuntimeInitService())->initializeLanding($landingId);
		if (!empty($runtimeInitResult['success']))
		{
			return true;
		}

		$this->addError(new Error(
			'Failed to initialize Tailwind runtime for the page.',
			(string)($runtimeInitResult['error'] ?? 'TAILWIND_RUNTIME_INIT_FAILED')
		));

		return false;
	}

	private function ensureAiSiteRuntimeSupported(int $landingId): bool
	{
		if ((new TailwindRuntimeEligibilityService())->isLandingSupported($landingId))
		{
			return true;
		}

		$this->addError(new Error(
			'AI site changes are available only for AI-created landing sites.',
			'AI_SITE_REQUIRED'
		));

		return false;
	}

	private function loadCreateAiSiteGeneration(int $generationId): ?Generation
	{
		if ($generationId <= 0)
		{
			$this->addError(new Error('Generation ID is missing.', 'GENERATION_ID_MISSING'));

			return null;
		}

		$generation = new Generation();
		if (!$generation->initById($generationId))
		{
			$this->addError(new Error('Generation was not found.', 'GENERATION_NOT_FOUND'));

			return null;
		}

		if (!$generation->getScenario() instanceof CreateAiSite)
		{
			$this->addError(new Error('Generation belongs to a different scenario.', 'WRONG_SCENARIO'));

			return null;
		}

		if ($generation->getAuthorId() !== Manager::getUserId())
		{
			$this->addError(new Error('Insufficient permissions to read generation.', 'ACCESS_DENIED'));

			return null;
		}

		return $generation;
	}

	private function loadChangeAiSiteGeneration(int $generationId): ?Generation
	{
		if ($generationId <= 0)
		{
			$this->addError(new Error('Generation ID is missing.', 'GENERATION_ID_MISSING'));

			return null;
		}

		$generation = new Generation();
		if (!$generation->initById($generationId))
		{
			$this->addError(new Error('Generation was not found.', 'GENERATION_NOT_FOUND'));

			return null;
		}

		if (!$generation->getScenario() instanceof ChangeAiSite)
		{
			$this->addError(new Error('Generation belongs to a different scenario.', 'WRONG_SCENARIO'));

			return null;
		}

		if ($generation->getAuthorId() !== Manager::getUserId())
		{
			$this->addError(new Error('Insufficient permissions to read generation.', 'ACCESS_DENIED'));

			return null;
		}

		return $generation;
	}

	private function buildChangeAiSiteResponse(Generation $generation, array $extra = []): array
	{
		$stateLandingId = ChangeAiSiteState::resolveLandingId($generation);
		$siteData = $generation->getSiteData();
		$landingId = $stateLandingId > 0 ? $stateLandingId : (int)($siteData->getLandingId() ?? 0);
		$siteId = (int)($siteData->getSiteId() ?? 0);
		$steps = $this->loadChangeAiSiteSteps(
			(int)($generation->getId() ?? 0),
			$generation->getScenario()
		);
		$currentStep = $this->resolveCurrentMagicSiteStep($steps, $generation->getStep());
		$result = ChangeAiSiteState::getResult($generation);
		$changeResult = $result;
		$changeResult['appliedOperations'] = ChangeAiSiteState::getPublicAppliedOperations($generation);
		$selectionDebug = ChangeAiSiteState::getSelectionDebug($generation);
		$actions = ChangeAiSiteState::getActions($generation);
		$htmlBlocks = ChangeAiSiteState::getHtmlBlocks($generation);
		$targetBlockIds = $this->extractChangeAiSiteTargetBlockIds($actions, $htmlBlocks);
		$tailwindRuntime = ChangeAiSiteState::getTailwindRuntime($generation);
		$finished = $generation->isFinished();
		$error = $generation->isError();
		$errorMessage = $error ? $this->buildChangeAiSiteErrorMessage($steps, $selectionDebug) : '';
		$message = $this->buildChangeAiSiteStateMessage(
			$finished,
			$error,
			$currentStep,
			$errorMessage,
			$result,
			$tailwindRuntime,
		);

		return array_merge([
			'success' => true,
			'generationId' => (int)($generation->getId() ?? 0),
			'finished' => $finished,
			'error' => $error,
			'errorMessage' => $errorMessage,
			'message' => $message,
			'siteId' => $siteId,
			'landingId' => $landingId,
			'currentStep' => $currentStep,
			'steps' => array_values($steps),
			'input' => ChangeAiSiteState::resolveInputLines($generation),
			'targetBlockIds' => $targetBlockIds,
			'targetCount' => count($targetBlockIds),
			'actions' => $actions,
			'selectionDebug' => $selectionDebug,
			'htmlBlocks' => $this->normalizeChangeAiSiteHtmlBlocks($htmlBlocks),
			'changeResult' => $changeResult,
			'changedCount' => count($result['changedIds'] ?? []),
			'updatedCount' => count($result['updatedIds'] ?? []),
			'createdCount' => count($result['createdIds'] ?? []),
			'deletedCount' => count($result['deletedIds'] ?? []),
			'movedCount' => count($result['movedIds'] ?? []),
			'skippedCount' => count($result['skippedIds'] ?? []),
			'tailwindRuntimeReady' => !empty($tailwindRuntime['success']),
			'tailwindRuntime' => $tailwindRuntime ?: null,
		], $extra);
	}

	private function buildMagicSiteResponse(Generation $generation, array $extra = []): ?array
	{
		$siteData = $generation->getSiteData();
		$siteId = (int)($siteData->getSiteId() ?? 0);
		$landingId = (int)($siteData->getLandingId() ?? 0);
		$context = new DevContextDto($siteId, $landingId);
		$finished = $generation->isFinished();
		$error = $generation->isError();
		if ($finished && !$error && $context->hasSite() && !$this->finalizeCompletedGenerationBinding($generation))
		{
			$this->addError(new Error(
				'Failed to finalize AI site binding before returning completed status.',
				'AI_SITE_BINDING_FINALIZE_FAILED'
			));

			return null;
		}

		$contextService = $this->getContextService();
		if ($context->hasSite())
		{
			$contextService->save($context);
		}

		$steps = $this->loadMagicSiteSteps(
			(int)($generation->getId() ?? 0),
			$generation->getScenario()
		);
		$currentStep = $this->resolveCurrentMagicSiteStep($steps, $generation->getStep());
		$enhanced = CreateAiSiteState::getEnhancedInput($generation);
		$structure = CreateAiSiteState::getStructure($generation);
		$htmlBlocks = CreateAiSiteState::getHtmlBlocks($generation);
		$tailwindRuntime = CreateAiSiteState::getTailwindRuntime($generation);
		$publication = $this->normalizeMagicSitePublication(CreateAiSiteState::getPublication($generation));
		$creationJournal = CreateAiSiteState::getCreationJournal($generation);
		$errorMessage = $error ? $this->buildMagicSiteErrorMessage($steps, $tailwindRuntime) : '';
		$recoverablePartial = $error
			&& !empty($creationJournal['recoverablePartial'])
			&& (int)($creationJournal['siteId'] ?? 0) > 0
		;

		$response = [
			'success' => true,
			'generationId' => (int)($generation->getId() ?? 0),
			'finished' => $finished,
			'error' => $error,
			'errorMessage' => $errorMessage,
			'message' => $this->buildMagicSiteStateMessage($finished, $error, $currentStep, $errorMessage),
			'siteId' => $context->getSiteId(),
			'landingId' => $context->getLandingId(),
			'hasSite' => $context->hasSite(),
			'currentStep' => $currentStep,
			'steps' => array_values($steps),
			'input' => CreateAiSiteState::resolveInputLines($generation),
			'enhanced' => $enhanced ?: null,
			'structure' => $structure,
			'structureCount' => count($structure),
			'htmlBlocks' => $this->normalizeMagicSiteHtmlBlocks($htmlBlocks),
			'htmlReadyCount' => $this->countReadyHtmlBlocks($htmlBlocks),
			'blockCreatedCount' => $this->countCreatedBlocks($htmlBlocks),
			'tailwindRuntimeReady' => !empty($tailwindRuntime['success']),
			'tailwindRuntime' => $tailwindRuntime ?: null,
			'published' => $publication['published'],
			'publicationWarning' => $publication['warning'],
			'publication' => $publication,
			'recoverablePartial' => $recoverablePartial,
			'draftSiteId' => $recoverablePartial ? (int)$creationJournal['siteId'] : 0,
			'draftLandingId' => $recoverablePartial ? (int)$creationJournal['landingId'] : 0,
			'partialStage' => $recoverablePartial ? (string)$creationJournal['stage'] : '',
			'creationJournal' => $creationJournal,
		];

		return array_merge($response, $contextService->getSiteLinks($context), $extra);
	}

	private function finalizeCompletedGenerationBinding(Generation $generation): bool
	{
		if ((new AiSiteBindingFinalizer())->finalizeCompletedGeneration($generation))
		{
			return true;
		}

		return CreateAiSiteState::getAiSiteBindingId($generation) <= 0;
	}

	private function normalizeMagicSitePublication(array $publication): array
	{
		return [
			'attempted' => !empty($publication['attempted']),
			'published' => !empty($publication['published']),
			'warning' => isset($publication['warning']) && is_scalar($publication['warning'])
				? (string)$publication['warning']
				: null,
			'errorCode' => isset($publication['errorCode']) && is_scalar($publication['errorCode'])
				? (string)$publication['errorCode']
				: null,
		];
	}

	private function loadChangeAiSiteSteps(int $generationId, ?IScenario $scenario = null): array
	{
		return $this->loadScenarioSteps($generationId, $scenario);
	}

	private function loadMagicSiteSteps(int $generationId, ?IScenario $scenario = null): array
	{
		return $this->loadScenarioSteps($generationId, $scenario);
	}

	private function loadScenarioSteps(int $generationId, ?IScenario $scenario = null): array
	{
		$steps = [];
		foreach ($this->getScenarioStepMeta($scenario) as $stepId => $meta)
		{
			$steps[$stepId] = [
				'stepId' => $stepId,
				'code' => (string)($meta['code'] ?? ''),
				'label' => (string)($meta['label'] ?? ''),
				'status' => 'new',
			];
		}

		if ($generationId <= 0 || $steps === [])
		{
			return $steps;
		}

		$query = StepsTable::query()
			->setSelect(['STEP_ID', 'STATUS'])
			->where('GENERATION_ID', '=', $generationId)
			->exec()
		;

		while ($row = $query->fetch())
		{
			$stepId = (int)($row['STEP_ID'] ?? 0);
			if (!isset($steps[$stepId]))
			{
				continue;
			}

			$steps[$stepId]['status'] = $this->resolveStepStatusLabel((int)($row['STATUS'] ?? StepStatuses::New->value));
		}

		return $steps;
	}

	/**
	 * @return array<int, array{code: string, label: string}>
	 */
	private function getScenarioStepMeta(?IScenario $scenario): array
	{
		if (!$scenario)
		{
			return [];
		}

		$map = $scenario->getMap();
		$meta = $scenario instanceof IStepMetaProvider ? $scenario->getStepMeta() : [];
		$result = [];
		foreach ($map as $stepId => $_step)
		{
			$result[$stepId] = [
				'code' => isset($meta[$stepId]['code']) ? (string)$meta[$stepId]['code'] : ('step_' . $stepId),
				'label' => isset($meta[$stepId]['label']) ? (string)$meta[$stepId]['label'] : ('Step ' . $stepId),
			];
		}

		return $result;
	}

	private function resolveCurrentMagicSiteStep(array $steps, ?int $generationStepId): array
	{
		foreach ($steps as $step)
		{
			if (($step['status'] ?? '') === 'error')
			{
				return $step;
			}
		}

		foreach ($steps as $step)
		{
			if (($step['status'] ?? '') === 'started')
			{
				return $step;
			}
		}

		$generationStepId = (int)$generationStepId;
		if ($generationStepId > 0 && isset($steps[$generationStepId]))
		{
			return $steps[$generationStepId];
		}

		$finishedSteps = array_filter($steps, static fn(array $step): bool => ($step['status'] ?? '') === 'finished');
		if ($finishedSteps)
		{
			return end($finishedSteps) ?: reset($steps);
		}

		return reset($steps) ?: [
			'stepId' => 0,
			'code' => 'unknown',
			'label' => 'Waiting to start',
			'status' => 'new',
		];
	}

	private function resolveStepStatusLabel(int $status): string
	{
		return match (StepStatuses::tryFrom($status))
		{
			StepStatuses::Started => 'started',
			StepStatuses::Finished => 'finished',
			StepStatuses::Error => 'error',
			default => 'new',
		};
	}

	private function buildMagicSiteErrorMessage(array $steps, array $tailwindRuntime = []): string
	{
		$messagesByStepId = $this->getCreateAiSiteErrorMessages();
		foreach ($steps as $step)
		{
			if (($step['status'] ?? '') !== 'error')
			{
				continue;
			}

			$stepId = (int)($step['stepId'] ?? 0);

			return $messagesByStepId[$stepId] ?? 'Scenario finished with an error.';
		}

		return 'Scenario finished with an error.';
	}

	/**
	 * @return array<int, string>
	 */
	private function getCreateAiSiteErrorMessages(): array
	{
		return [
			10 => 'Failed to prepare scenario input data.',
			20 => 'Failed to enhance user input.',
			30 => 'Failed to prepare site data.',
			40 => 'Failed to generate preview image.',
			50 => 'Failed to generate Tailwind structure.',
			60 => 'Failed to generate HTML for blocks.',
			70 => 'Failed to improve block HTML.',
			80 => 'Failed to create site and landing.',
			90 => 'Failed to initialize Tailwind runtime for the page.',
			100 => 'Failed to prepare block HTML markup.',
			110 => 'Failed to prepare CRM forms in block markup.',
			120 => 'Failed to add HTML blocks to landing.',
			130 => 'Failed to sync page font theme.',
			140 => 'Failed to prepare created block HTML markup.',
			150 => 'Failed to generate images for blocks.',
			160 => 'Failed to publish site.',
			1000 => 'Scenario finished with an error.',
		];
	}

	private function buildChangeAiSiteErrorMessage(array $steps, array $selectionDebug = []): string
	{
		foreach ($steps as $step)
		{
			if (($step['status'] ?? '') !== 'error')
			{
				continue;
			}

			$message = ChangeAiSiteErrorMessages::resolve((int)($step['stepId'] ?? 0), $selectionDebug);

			return $message !== '' ? $message : 'Page change scenario finished with an error.';
		}

		return 'Page change scenario finished with an error.';
	}

	private function buildChangeAiSiteSelectionErrorMessage(array $selectionDebug): string
	{
		return ChangeAiSiteErrorMessages::resolve(30, $selectionDebug);
	}

	private function buildMagicSiteStateMessage(bool $finished, bool $error, array $currentStep, string $errorMessage): string
	{
		if ($error)
		{
			return $errorMessage !== '' ? $errorMessage : 'Scenario finished with an error.';
		}

		if ($finished)
		{
			return 'Site has been created.';
		}

		$label = trim((string)($currentStep['label'] ?? ''));

		return $label !== '' ? 'In progress: ' . $label . '.' : 'Scenario is running.';
	}

	private function buildChangeAiSiteStateMessage(
		bool $finished,
		bool $error,
		array $currentStep,
		string $errorMessage,
		array $result,
		array $tailwindRuntime = [],
	): string
	{
		if ($error)
		{
			return $errorMessage !== '' ? $errorMessage : 'Page change scenario finished with an error.';
		}

		if ($finished)
		{
			$changed = count(is_array($result['changedIds'] ?? null) ? $result['changedIds'] : []);
			$updated = count(is_array($result['updatedIds'] ?? null) ? $result['updatedIds'] : []);
			$created = count(is_array($result['createdIds'] ?? null) ? $result['createdIds'] : []);
			$deleted = count(is_array($result['deletedIds'] ?? null) ? $result['deletedIds'] : []);
			$moved = count(is_array($result['movedIds'] ?? null) ? $result['movedIds'] : []);
			$skipped = count(is_array($result['skippedIds'] ?? null) ? $result['skippedIds'] : []);
			$message = "Page update completed. Blocks changed: {$changed}. Updated: {$updated}. Added: {$created}. Deleted: {$deleted}. Moved: {$moved}. Blocks skipped: {$skipped}.";
			$warning = $this->buildChangeAiSiteTailwindWarning($tailwindRuntime);
			if ($warning !== '')
			{
				$message .= ' ' . $warning;
			}

			return $message;
		}

		$label = trim((string)($currentStep['label'] ?? ''));
		$message = $label !== '' ? 'In progress: ' . $label . '.' : 'Scenario is running.';
		$warning = $this->buildChangeAiSiteTailwindWarning($tailwindRuntime);
		if ($warning !== '')
		{
			$message .= ' ' . $warning;
		}

		return $message;
	}

	private function buildChangeAiSiteTailwindWarning(array $tailwindRuntime): string
	{
		$cleanupErrors = $tailwindRuntime['cleanup']['errors'] ?? null;
		if (is_array($cleanupErrors) && !empty($cleanupErrors))
		{
			return 'Warning: Not all old managed CSS files were removed automatically.';
		}

		if (!empty($tailwindRuntime['success']))
		{
			return '';
		}

		if ($tailwindRuntime === [])
		{
			return '';
		}

		return 'Warning: Failed to switch page to Tailwind CSS rebuild mode.';
	}

	private function normalizeMagicSiteHtmlBlocks(array $htmlBlocks): array
	{
		$normalized = [];
		foreach ($htmlBlocks as $item)
		{
			if (!is_array($item))
			{
				continue;
			}

			$normalized[] = [
				'position' => (int)($item['position'] ?? 0),
				'spec' => isset($item['spec']) && is_array($item['spec']) ? $item['spec'] : [],
				'html' => trim((string)($item['html'] ?? '')),
				'blockId' => (int)($item['blockId'] ?? 0),
				'anchor' => trim((string)($item['anchor'] ?? '')),
				'improved' => !empty($item['improved']),
			];
		}

		usort($normalized, static function (array $left, array $right): int
		{
			return ((int)($left['position'] ?? 0)) <=> ((int)($right['position'] ?? 0));
		});

		return $normalized;
	}

	private function normalizeChangeAiSiteHtmlBlocks(array $htmlBlocks): array
	{
		$normalized = [];
		foreach ($htmlBlocks as $item)
		{
			if (!is_array($item))
			{
				continue;
			}

			$normalized[] = [
				'actionId' => trim((string)($item['actionId'] ?? '')),
				'actionType' => trim((string)($item['actionType'] ?? '')),
				'blockId' => (int)($item['blockId'] ?? 0),
				'sourceBlockId' => (int)($item['sourceBlockId'] ?? 0),
				'generatedHtml' => trim((string)($item['generatedHtml'] ?? '')),
				'applyStatus' => trim((string)($item['applyStatus'] ?? '')),
				'placement' => isset($item['placement']) && is_array($item['placement']) ? $item['placement'] : [],
				'aiMeta' => isset($item['aiMeta']) && is_array($item['aiMeta']) ? $item['aiMeta'] : [],
			];
		}

		usort($normalized, static function (array $left, array $right): int
		{
			return ((int)($left['blockId'] ?? 0)) <=> ((int)($right['blockId'] ?? 0));
		});

		return $normalized;
	}

	private function extractChangeAiSiteTargetBlockIds(array $actions, array $htmlBlocks): array
	{
		$ids = $this->extractChangeAiSiteActionBlockIds($actions);
		if ($ids !== [])
		{
			return $ids;
		}

		return $this->extractChangeAiSiteHtmlBlockIds($htmlBlocks);
	}

	private function extractChangeAiSiteActionBlockIds(array $actions): array
	{
		$ids = [];
		foreach ($actions as $action)
		{
			if (!is_array($action) || ($action['type'] ?? '') === 'add_block')
			{
				continue;
			}

			$blockId = (int)($action['blockId'] ?? 0);
			if ($blockId > 0)
			{
				$ids[] = $blockId;
			}
		}

		return array_values(array_unique($ids));
	}

	private function extractChangeAiSiteHtmlBlockIds(array $htmlBlocks): array
	{
		$ids = [];
		foreach ($htmlBlocks as $item)
		{
			if (!is_array($item))
			{
				continue;
			}

			$blockId = (int)($item['blockId'] ?? 0);
			if ($blockId > 0)
			{
				$ids[] = $blockId;
			}
		}

		return array_values(array_unique($ids));
	}

	private function countReadyHtmlBlocks(array $htmlBlocks): int
	{
		$count = 0;
		foreach ($htmlBlocks as $item)
		{
			if (is_array($item) && trim((string)($item['html'] ?? '')) !== '')
			{
				$count++;
			}
		}

		return $count;
	}

	private function countCreatedBlocks(array $htmlBlocks): int
	{
		$count = 0;
		foreach ($htmlBlocks as $item)
		{
			if (is_array($item) && (int)($item['blockId'] ?? 0) > 0)
			{
				$count++;
			}
		}

		return $count;
	}

	private function getContextService(): DevContextService
	{
		static $service = null;

		if (!$service instanceof DevContextService)
		{
			$service = new DevContextService();
		}

		return $service;
	}
}
