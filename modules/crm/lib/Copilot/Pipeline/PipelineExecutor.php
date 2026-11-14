<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\Pipeline;

use Bitrix\Bizproc\Starter\Dto\ContextDto;
use Bitrix\Bizproc\Starter\Enum;
use Bitrix\Bizproc\Starter\Starter;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Integration\AI\Operation\AbstractOperation;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Operation\ScoreCallV2;
use Bitrix\Crm\Integration\AI\Operation\SelectCallScoreScript;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\MultiValueStoreService;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use CCrmOwnerType;
use Psr\Log\LoggerInterface;

final readonly class PipelineExecutor
{
	private LoggerInterface $logger;

	public function __construct(
		private ScenarioRegistry $scenarioRegistry,
		private StepResultResolver $resultResolver,
		private StepFactory $stepFactory,
	)
	{
		$this->logger = AIManager::logger();
	}

	/**
	 * Entry point 1: Start or resume a scenario.
	 * Called from controllers/AIManager for manual or programmatic launch.
	 */
	public function startOrResume(StepContext $context): ?Result
	{
		$scenario = $this->scenarioRegistry->getByName($context->getScenarioName());
		if (!$scenario)
		{
			$this->logger->error(
				'{date}: {class}: Unknown scenario {scenario}' . PHP_EOL,
				[
					'class' => self::class,
					'scenario' => $context->getScenarioName(),
				],
			);

			return null;
		}

		$steps = $scenario->resolveSteps($context->getActivityProvider());

		// Warm JobRepository caches in a single query so that individual resolve() calls below hit cache
		JobRepository::getInstance()->warmCacheForActivity($context->getActivityId());

		$lastExistingResult = null;
		foreach ($steps as $index => $operationClass)
		{
			if ($operationClass === ScoreCallV2::class && $this->isManualCallAssessment($context))
			{
				return $this->launchCallAssessment($context);
			}

			$existingResult = $this->resultResolver->resolve($operationClass, $context);
			if ($operationClass === SummarizeCallTranscription::class && $this->isManualCallAssessment($context))
			{
				$waitResult = $this->coordinateManualCallAssessmentSummary($existingResult, $context);
				if ($waitResult !== null)
				{
					return $waitResult;
				}
			}

			if ($existingResult?->isPending())
			{
				return $existingResult;
			}

			if ($existingResult?->isSuccess())
			{
				if ($operationClass::shouldRelaunch($existingResult, $context))
				{
					return $this->launchStep($operationClass, $context, $steps, $index);
				}

				if (!$operationClass::canProceedToNextStep($existingResult, $context))
				{
					return $existingResult;
				}

				$this->ensureNextTypeId($existingResult, $steps, $index, $context);

				$lastExistingResult = $existingResult;

				continue;
			}

			return $this->launchStep($operationClass, $context, $steps, $index);
		}

		return $lastExistingResult;
	}

	/**
	 * Entry point 2: Continue chain after async step completion.
	 * Called from EventHandler::onQueueJobExecute.
	 */
	public function continueAfterCompletion(
		Result $completedResult,
		string $scenarioName,
		?ItemIdentifier $manualFillTarget = null,
		array $contextExtra = [],
	): void
	{
		if ($completedResult->getTypeId() === SummarizeCallTranscription::TYPE_ID)
		{
			$waitResult = $this->coordinateManualCallAssessmentSummary($completedResult);
			if ($waitResult !== null && ($contextExtra['manualCallAssessment'] ?? null) === true)
			{
				return;
			}
		}

		$scenario = $this->scenarioRegistry->getByName($scenarioName);
		if (!$scenario)
		{
			return;
		}

		$context = $this->buildContextFromResult(
			$completedResult,
			$scenarioName,
			$manualFillTarget,
			$contextExtra,
		);

		if ($context->getActivityId() <= 0)
		{
			return;
		}

		$steps = $scenario->resolveSteps($context->getActivityProvider());

		$currentIndex = $this->findStepIndex($steps, $completedResult->getTypeId());
		if ($currentIndex === null)
		{
			$this->logger->warning(
				'{date}: PipelineExecutor: Completed operation {typeId} not found in scenario {scenario} steps' . PHP_EOL,
				['typeId' => $completedResult->getTypeId(), 'scenario' => $scenarioName],
			);

			return;
		}

		$operationClass = $steps[$currentIndex];
		if (!$operationClass::canProceedToNextStep($completedResult, $context))
		{
			$this->logger->info(
				'{date}: PipelineExecutor: canProceedToNextStep returned false for {operation} in scenario {scenario}' . PHP_EOL,
				['operation' => $operationClass, 'scenario' => $scenarioName],
			);

			return;
		}

		$nextIndex = $currentIndex + 1;
		if (isset($steps[$nextIndex]))
		{
			$launchResult = $this->isManualCallAssessment($context)
				? $this->startOrResume($context)
				: $this->launchStep($steps[$nextIndex], $context, $steps, $nextIndex);
			if ($launchResult === null)
			{
				$this->logger->warning(
					'{date}: PipelineExecutor: continueAfterCompletion produced no launch result for next step {step} in scenario {scenario} (all remaining steps were skipped)' . PHP_EOL,
					['step' => $steps[$nextIndex], 'scenario' => $scenarioName],
				);
			}
			elseif (!$launchResult->isSuccess())
			{
				$this->logger->error(
					'{date}: PipelineExecutor: Next step {step} in scenario {scenario} failed to launch: {errors}' . PHP_EOL,
					[
						'step' => $steps[$nextIndex],
						'scenario' => $scenarioName,
						'errors' => $launchResult->getErrorMessages(),
					],
				);
			}
		}
	}

	/**
	 * Launch a step, or skip it if StepFactory cannot create the operation
	 * (e.g., CallAssessment disabled for ScoreCall). On skip, advances to the next step.
	 */
	private function launchStep(
		string $operationClass,
		StepContext $context,
		array $steps,
		int $stepIndex,
	): ?Result
	{
		if ($operationClass === ScoreCallV2::class && $this->isManualCallAssessment($context))
		{
			return $this->launchCallAssessment($context);
		}

		$this->logger->info(
			'{date}: PipelineExecutor: Launching step {step} (index {index}) in scenario {scenario}' . PHP_EOL,
			['step' => $operationClass, 'index' => $stepIndex, 'scenario' => $context->getScenarioName()],
		);

		$operation = $this->stepFactory->create($operationClass, $context);
		if (!$operation)
		{
			$this->logger->info(
				'{date}: PipelineExecutor: Skipping step {step} in scenario {scenario} (StepFactory returned null)' . PHP_EOL,
				['step' => $operationClass, 'scenario' => $context->getScenarioName()],
			);

			// Skip to next step if available.
			// Recursion depth bounded by scenario step count (max 5 for FullScenario).
			$nextIndex = $stepIndex + 1;
			if (isset($steps[$nextIndex]))
			{
				return $this->launchStep($steps[$nextIndex], $context, $steps, $nextIndex);
			}

			return null;
		}

		$operation->setIsManualLaunch($context->isManualLaunch());
		$operation->setScenario($context->getScenarioName());
		// Re-propagate the manual clicked target into the engine context on every step so it survives
		// the async boundary and is present on the last hop before FillFields (ALG-01). Null on the
		// auto path — TargetResolver keeps resolving the priority Deal/Lead as before.
		$operation->setManualFillTarget($context->getManualTarget());

		$this->forwardContextExtras($operation, $context);

		// Only set explicit NEXT_TYPE_ID for scenarios that have unique step transitions.
		// FULL scenario uses null NEXT_TYPE_ID (from Scenario::getNextTypeIdByScenario('full'))
		// so ScenarioResolver's fallback can distinguish it from FILL_FIELDS at shared transitions.
		// @see Scenario::getNextTypeIdByScenario() — returns null for FULL
		// @see ScenarioResolver::resolve() — null fallback
		$scenarioNextTypeId = Scenario::getNextTypeIdByScenario($context->getScenarioName());
		if ($scenarioNextTypeId !== null)
		{
			$nextTypeId = isset($steps[$stepIndex + 1]) ? $steps[$stepIndex + 1]::TYPE_ID : 0;
			$operation->setNextTypeIdOverride($nextTypeId);
		}

		return $operation->launch();
	}

	private function forwardContextExtras(AbstractOperation $operation, StepContext $context): void
	{
		foreach ([
			'targetOwnerTypeId',
			'targetOwnerId',
			'triggerDirection',
			'fillFieldsEntityAllowed',
			'manualCallAssessment',
		] as $key)
		{
			$value = $context->getExtra($key);
			if ($value !== null)
			{
				$operation->setContextExtra($key, $value);
			}
		}
	}

	private function isManualCallAssessment(StepContext $context): bool
	{
		return $context->isManualLaunch()
			&& $context->getScenarioName() === Scenario::CALL_SCORING_V2_SCENARIO
			&& $context->getExtra('manualCallAssessment') === true;
	}

	private function launchCallAssessment(StepContext $context): Result
	{
		$result = new Result(ScoreCallV2::TYPE_ID);
		if (!Loader::includeModule('bizproc'))
		{
			return $result->addError(ErrorCode::getAIEngineNotFoundError());
		}

		// Script selection and scoring belong to the workflow; start it only after the prerequisites.
		$triggerResult = Starter::getByScenario(Enum\Scenario::onEvent)
			->setContext(new ContextDto('crm'))
			->addEvent('CrmCallAssessmentTrigger', [], [
				'ActivityId' => $context->getActivityId(),
				'AssessmentSettingsId' => 0,
				'UserId' => $context->getUserId(),
			])
			->start()
		;
		if (!$triggerResult->isSuccess())
		{
			$result->addErrors($triggerResult->getErrors());
			$result->addError(ErrorCode::getAIEngineNotFoundError());
		}

		return $result;
	}

	private function coordinateManualCallAssessmentSummary(?Result $result, ?StepContext $request = null): ?Result
	{
		$activityId = $request?->getActivityId() ?? $result?->getTarget()?->getEntityId() ?? 0;
		if ($activityId <= 0)
		{
			return null;
		}

		// One replaceable wait per activity, tied to the exact summary job and the requesting user.
		$key = 'crm_manual_call_assessment_summary_' . $activityId;
		$store = MultiValueStoreService::getInstance();
		if ($request === null && $store->getFirstValue($key) === null)
		{
			return null;
		}

		$connection = Application::getConnection();
		while (!$connection->lock($key, 1))
		{
		}

		try
		{
			$jobId = $result?->getJobId();
			$value = $store->getFirstValue($key);
			[$waitingJobId, $userId] = array_map('intval', explode(':', $value ?? '0:0'));
			if ($request !== null)
			{
				if ($jobId === null)
				{
					$store->deleteAll($key);

					return null;
				}

				if ($waitingJobId !== $jobId)
				{
					$userId = $request->getUserId();
					$info = JobRepository::getInstance()->getContextAdditionalInfoByJobId($jobId);
					if (($info['manualCallAssessment'] ?? null) === true)
					{
						$userId = $result->getUserId() ?? $userId;
					}
					$value = $jobId . ':' . $userId;
					$store->set($key, $value);
					$waitingJobId = $jobId;
				}

				JobRepository::getInstance()->cleanRuntimeCache();
				$result = JobRepository::getInstance()->getSummarizeCallTranscriptionResultByActivity($activityId, $jobId);
				if (
					$result !== null
					&& !$result->isPending()
					&& (
						JobRepository::getInstance()->getPendingJobByActivity($activityId, ScoreCallV2::TYPE_ID) !== null
						|| JobRepository::getInstance()->getPendingJobByActivity(
							$activityId,
							SelectCallScoreScript::TYPE_ID,
						) !== null
					)
				)
				{
					$store->delete($key, $value);

					return $result;
				}
			}

			if ($waitingJobId !== $jobId || $value === null)
			{
				return null;
			}
			if ($result?->isPending())
			{
				return $result;
			}

			$store->delete($key, $value);
			$context = new StepContext(
				activityId: $activityId,
				userId: $userId,
				scenarioName: Scenario::CALL_SCORING_V2_SCENARIO,
				isManualLaunch: true,
			);
			if ($result === null)
			{
				return (new Result(SummarizeCallTranscription::TYPE_ID))->addError(ErrorCode::getNotFoundError());
			}
			if (!SummarizeCallTranscription::canProceedToNextStep($result, $context))
			{
				return $result;
			}
			if (!is_array(Container::getInstance()->getActivityBroker()->getById($activityId)))
			{
				return (new Result(SummarizeCallTranscription::TYPE_ID))->addError(ErrorCode::getNotFoundError());
			}

			return $this->launchCallAssessment($context);
		}
		finally
		{
			$connection->unlock($key);
		}
	}

	/**
	 * Patch NEXT_TYPE_ID on an already-completed job when the scenario changes.
	 * Only issues UPDATE when the stored value actually differs (avoids unnecessary DB writes and cache invalidation).
	 * Skips patching for scenarios that use null NEXT_TYPE_ID convention (e.g., FULL).
	 */
	private function ensureNextTypeId(Result $result, array $steps, int $currentIndex, StepContext $context): void
	{
		// Don't patch NEXT_TYPE_ID for scenarios using null convention (FULL).
		// Patching would overwrite null with an explicit TYPE_ID, breaking ScenarioResolver fallback.
		if (Scenario::getNextTypeIdByScenario($context->getScenarioName()) === null)
		{
			return;
		}

		$expectedNextTypeId = isset($steps[$currentIndex + 1])
			? $steps[$currentIndex + 1]::TYPE_ID
			: 0;

		$jobId = $result->getJobId();

		// Cast to int for safe comparison — ORM EnumField may return string
		$rawNextTypeId = $result->getNextTypeId();
		$currentNextTypeId = $rawNextTypeId !== null ? (int)$rawNextTypeId : null;

		if ($jobId && $currentNextTypeId !== $expectedNextTypeId)
		{
			$this->logger->debug(
				'{date}: PipelineExecutor: Patching NEXT_TYPE_ID from {current} to {expected} for job {jobId}' . PHP_EOL,
				['current' => $currentNextTypeId, 'expected' => $expectedNextTypeId, 'jobId' => $jobId],
			);

			$updateResult = QueueTable::update($jobId, [
				'NEXT_TYPE_ID' => $expectedNextTypeId,
			]);

			if (!$updateResult->isSuccess())
			{
				$this->logger->error(
					'{date}: PipelineExecutor: Failed to patch NEXT_TYPE_ID for job {jobId}: {errors}' . PHP_EOL,
					['jobId' => $jobId, 'errors' => implode(', ', $updateResult->getErrorMessages())],
				);
			}
		}
	}

	private function buildContextFromResult(
		Result $completedResult,
		string $scenarioName,
		?ItemIdentifier $manualFillTarget = null,
		array $contextExtra = [],
	): StepContext
	{
		$activityId = $completedResult->getTarget()?->getEntityId() ?? 0;

		if ($completedResult->getTarget()?->getEntityTypeId() !== CCrmOwnerType::Activity)
		{
			$parentJobId = $completedResult->getParentJobId();
			if ($parentJobId)
			{
				$parentJob = QueueTable::query()
					->setSelect(['ENTITY_ID', 'ENTITY_TYPE_ID'])
					->where('ID', $parentJobId)
					->where('ENTITY_TYPE_ID', CCrmOwnerType::Activity)
					->fetchObject()
				;

				if ($parentJob)
				{
					$activityId = $parentJob->getEntityId();
				}
			}
		}

		if ($activityId <= 0)
		{
			$this->logger->warning(
				'{date}: PipelineExecutor: Could not resolve activityId from result in scenario {scenario}' . PHP_EOL,
				['scenario' => $scenarioName],
			);
		}

		// Recover activity provider for correct skip-transcription step resolution
		$activityProvider = null;
		if ($activityId > 0)
		{
			$activity = Container::getInstance()->getActivityBroker()->getById($activityId);
			$activityProvider = is_array($activity) ? ($activity['PROVIDER_ID'] ?? null) : null;
		}

		$includeOwnerKeys = $activityProvider === \Bitrix\Crm\Activity\Provider\Email::getId();
		$extra = $this->filterContextExtra($contextExtra, $includeOwnerKeys);
		$fillFieldsEntityAllowed = $completedResult->getFillFieldsEntityAllowedSnapshot();
		if (!array_key_exists('fillFieldsEntityAllowed', $extra) && $fillFieldsEntityAllowed !== null)
		{
			$extra['fillFieldsEntityAllowed'] = $fillFieldsEntityAllowed;
		}

		if ($includeOwnerKeys)
		{
			$extra = array_replace(
				$this->recoverExtraFromJob($completedResult->getJobId(), true),
				$extra,
			);
		}

		$context = new StepContext(
			activityId: $activityId,
			userId: $completedResult->getUserId() ?? Container::getInstance()->getContext()->getUserId(),
			scenarioName: $scenarioName,
			isManualLaunch: $completedResult->isManualLaunch(),
			activityProvider: $activityProvider,
			// Recovered from the engine context (best-effort, same channel as `scenario`) so a fresh
			// manual launch keeps filling the clicked entity across the async boundary (ALG-01). Null
			// for the auto path, where StepContext::resolveFillTarget falls back to TargetResolver.
			manualTarget: $manualFillTarget,
		);

		foreach ($extra as $key => $value)
		{
			$context = $context->withExtra($key, $value);
		}

		return $context;
	}

	private function recoverExtraFromJob(?int $jobId, bool $includeOwnerKeys): array
	{
		return $this->filterContextExtra(
			JobRepository::getInstance()->getContextAdditionalInfoByJobId($jobId),
			$includeOwnerKeys,
		);
	}

	private function filterContextExtra(array $additionalInfo, bool $includeOwnerKeys): array
	{
		$extra = [];
		if (array_key_exists('fillFieldsEntityAllowed', $additionalInfo))
		{
			$extra['fillFieldsEntityAllowed'] = (bool)$additionalInfo['fillFieldsEntityAllowed'];
		}
		if (($additionalInfo['manualCallAssessment'] ?? null) === true)
		{
			$extra['manualCallAssessment'] = true;
		}

		if (!$includeOwnerKeys)
		{
			return $extra;
		}

		if (isset($additionalInfo['targetOwnerTypeId']))
		{
			$extra['targetOwnerTypeId'] = (int)$additionalInfo['targetOwnerTypeId'];
		}
		if (isset($additionalInfo['targetOwnerId']))
		{
			$extra['targetOwnerId'] = (int)$additionalInfo['targetOwnerId'];
		}
		if (isset($additionalInfo['triggerDirection']))
		{
			$extra['triggerDirection'] = (int)$additionalInfo['triggerDirection'];
		}

		return $extra;
	}

	private function findStepIndex(array $steps, int $typeId): ?int
	{
		foreach ($steps as $index => $operationClass)
		{
			if ($operationClass::TYPE_ID === $typeId)
			{
				return $index;
			}
		}

		return null;
	}
}
