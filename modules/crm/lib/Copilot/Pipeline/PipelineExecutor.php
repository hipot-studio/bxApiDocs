<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\Pipeline;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Service\Container;
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
			$existingResult = $this->resultResolver->resolve($operationClass, $context);
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
	public function continueAfterCompletion(Result $completedResult, string $scenarioName): void
	{
		$scenario = $this->scenarioRegistry->getByName($scenarioName);
		if (!$scenario)
		{
			return;
		}

		$context = $this->buildContextFromResult($completedResult, $scenarioName);

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
			$launchResult = $this->launchStep($steps[$nextIndex], $context, $steps, $nextIndex);
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

	private function buildContextFromResult(Result $completedResult, string $scenarioName): StepContext
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
			$activityProvider = $activity['PROVIDER_ID'] ?? null;
		}

		return new StepContext(
			activityId: $activityId,
			userId: $completedResult->getUserId() ?? Container::getInstance()->getContext()->getUserId(),
			scenarioName: $scenarioName,
			isManualLaunch: $completedResult->isManualLaunch(),
			activityProvider: $activityProvider,
		);
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
