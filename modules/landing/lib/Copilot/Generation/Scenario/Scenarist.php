<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Scenario;

use Bitrix\Landing\Copilot\Connector;
use Bitrix\Landing\Copilot\Connector\AI\RequestLimiter;
use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Step\Base\IStep;
use Bitrix\Landing\Copilot\Generation\Step\Base\RuntimeRequestQuotaProvider;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Type\RequestQuotaDto;
use Bitrix\Landing\Copilot\Generation\Type\ScenarioStepDto;
use Bitrix\Landing\Copilot\Generation\Type\StepStatuses;
use Bitrix\Landing\Copilot\Model\StepsTable;
use Bitrix\Landing\Copilot\Services\FirstSiteGenerationService;
use Bitrix\Landing\Metrika;
use Bitrix\Main\Loader;

/**
 * Class Scenarist
 *
 * Manages the execution flow of a scenario consisting of multiple steps for AI-powered content generation.
 * Handles step status tracking, error handling, quota checks, analytics, and callback hooks for step changes and
 * completion.
 */
class Scenarist
{
	private const EVENT_STEP = 'onExecuteStep';

	private IScenario $scenario;
	private Generation $generation;

	private ?int $stepId;

	/**
	 * @var ScenarioStepDto[] Array of scenario steps indexed by step ID.
	 */
	private array $steps;
	/**
	 * @var array<int, list<int>>|null
	 */
	private ?array $normalizedAsyncRelations = null;

	/**
	 * @var callable|null Callback invoked when the step ID changes and must be saved.
	 */
	private $onStepChangeCallback;

	/**
	 * @var callable|null Callback invoked when site data changes and must be saved.
	 */
	private $onSaveCallback;

	/**
	 * @var callable|null Callback invoked when the scenario is finished.
	 */
	private $onFinishCallback;

	private RequestLimiter $requestLimiter;

	/**
	 * Scenarist constructor.
	 *
	 * @param IScenario $scenario The scenario instance to execute.
	 * @param Generation $generation The generation context.
	 */
	public function __construct(IScenario $scenario, Generation $generation)
	{
		$this->scenario = $scenario;
		$this->generation = $generation;

		$this->stepId = $this->generation->getStep();

		$this->initSteps();
	}

	/**
	 * Initializes the steps array from the scenario map and loads persisted step statuses.
	 *
	 * @return void
	 */
	private function initSteps(): void
	{
		foreach ($this->scenario->getMap() as $stepId => $step)
		{
			$step->init($this->generation, $stepId);
			$scenarioStep = new ScenarioStepDto(
				$stepId,
				$step,
				StepStatuses::New,
			);
			$this->steps[$stepId] = $scenarioStep;
		}

		$query = StepsTable::query()
			->setSelect(['ID', 'STEP_ID', 'STATUS'])
			->where('GENERATION_ID', $this->generation->getId())
			->exec()
		;
		while ($step = $query->fetch())
		{
			if (
				isset($this->steps[(int)$step['STEP_ID']])
				&& StepStatuses::tryFrom((int)$step['STATUS'])
			)
			{
				$this->steps[(int)$step['STEP_ID']]->status =
					StepStatuses::from((int)$step['STATUS']);
				$this->steps[(int)$step['STEP_ID']]->entityId = (int)$step['ID'];
			}
		}
	}

	/**
	 * Returns the current step ID.
	 *
	 * @return int|null
	 */
	public function getStep(): ?int
	{
		return $this->stepId;
	}

	/**
	 * Checks if the scenario is finished.
	 *
	 * Relies on persisted step rows only, so steps added to the scenario map
	 * after a generation was completed do not mark it as unfinished.
	 *
	 * @return bool True if the scenario is finished, false otherwise.
	 */
	public function isFinished(): bool
	{
		return $this->isFinishedByPersistedSteps();
	}

	private function isFinishedByPersistedSteps(): bool
	{
		$hasPersistedSteps = false;
		foreach ($this->steps as $step)
		{
			if (!isset($step->entityId))
			{
				continue;
			}

			$hasPersistedSteps = true;
			if ($step->status !== StepStatuses::Finished)
			{
				return false;
			}
		}

		if (!$hasPersistedSteps)
		{
			return false;
		}

		$pointerStep = $this->stepId !== null ? ($this->steps[$this->stepId] ?? null) : null;
		if (
			$pointerStep === null
			|| !isset($pointerStep->entityId)
			|| $pointerStep->status !== StepStatuses::Finished
		)
		{
			return false;
		}

		return !$this->hasExecutableAhead($this->stepId);
	}

	private function hasExecutableAhead(int $pointer): bool
	{
		$currentId = $pointer;
		$visited = [$pointer => true];

		while (true)
		{
			$nextId = $this->scenario->getNextStepId($currentId);
			if ($nextId === null || isset($visited[$nextId]) || !$this->scenario->checkStep($nextId))
			{
				return false;
			}

			$visited[$nextId] = true;
			$nextStep = $this->steps[$nextId] ?? null;
			if (isset($nextStep->entityId) && $nextStep->status === StepStatuses::Finished)
			{
				$currentId = $nextId;
				continue;
			}

			return true;
		}
	}

	/**
	 * Marks all scenario steps as finished.
	 *
	 * @return void
	 */
	public function finish(): void
	{
		foreach ($this->steps as $step)
		{
			if ($step->status !== StepStatuses::Finished)
			{
				$this->saveStepStatus($step, StepStatuses::Finished);
			}
		}
	}

	/**
	 * Checks if at least one scenario step has an error and is not executed.
	 *
	 * @return bool True if any step is in error state, false otherwise.
	 */
	public function isError(): bool
	{
		foreach ($this->steps as $step)
		{
			if ($step->status === StepStatuses::Error)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Prepares the scenario to restart generation after an error by clearing errors in all steps.
	 *
	 * @return void
	 */
	public function clearErrors(): void
	{
		foreach ($this->steps as $step)
		{
			if (
				$step->status->value > StepStatuses::New->value
				&& $step->status->value < StepStatuses::Finished->value
			)
			{
				$step->step->clearErrors();
				$this->saveStepStatus($step, StepStatuses::New);
			}
		}
	}

	/**
	 * Sets a callback to be called when the step ID changes.
	 *
	 * @param callable $callback
	 *
	 * @return $this
	 */
	public function onStepChange(callable $callback): self
	{
		$this->onStepChangeCallback = $callback;

		return $this;
	}

	/**
	 * Invokes the step change callback if set.
	 *
	 * @return void
	 */
	private function callOnStepChange(): void
	{
		if (isset($this->onStepChangeCallback) && is_int($this->stepId))
		{
			call_user_func($this->onStepChangeCallback, $this->stepId);
		}
	}

	/**
	 * Sets a callback to be called when the scenario state should be saved.
	 *
	 * @param callable $callback
	 *
	 * @return $this
	 */
	public function onSave(callable $callback): self
	{
		$this->onSaveCallback = $callback;

		return $this;
	}

	/**
	 * Invokes the save callback if set.
	 *
	 * @return void
	 */
	private function callOnSave(): void
	{
		if (isset($this->onSaveCallback))
		{
			call_user_func($this->onSaveCallback);
		}
	}

	/**
	 * Sets a callback to be called when the scenario is finished.
	 *
	 * @param callable $callback
	 *
	 * @return $this
	 */
	public function onFinish(callable $callback): self
	{
		$this->onFinishCallback = $callback;

		return $this;
	}

	/**
	 * Invokes the finish callback if set.
	 *
	 * @return void
	 */
	private function callOnFinish(): void
	{
		if (isset($this->onFinishCallback))
		{
			call_user_func($this->onFinishCallback);
		}
	}

	/**
	 * Executes the scenario, running steps in order and handling errors, quotas, and analytics.
	 *
	 * @return void
	 *
	 * @throws GenerationException If a step fails or a quota is exceeded.
	 */
	public function execute(): void
	{
		if (!$this->scenario->checkStep($this->stepId))
		{
			return;
		}

		$this->stepId = $this->stepId ?? $this->scenario->getFirstStepId();
		if (!$this->stepId)
		{
			return;
		}

		if ($this->stepId === $this->scenario->getFirstStepId())
		{
			$this->sendMetrikaStart();
		}

		foreach ($this->steps as $stepId => $stepDto)
		{
			if ($stepId > $this->stepId)
			{
				break;
			}

			if (!$this->isNeedExecuteStep($stepDto))
			{
				continue;
			}

			try
			{
				$this->executeStep($stepDto);
			}
			catch (GenerationException $e)
			{
				$this->saveStepStatus($stepDto, StepStatuses::Error);
				throw $e;
			}

			if (
				$this->changeStep($stepDto)
				|| $stepDto->step->isChanged()
				|| $stepDto->step->isFinished()
			)
			{
				$this->callOnSave();
			}

			$this->generation->getEvent()->send(self::EVENT_STEP);
		}

		if ($this->isFinished())
		{
			$this->scenario->onFinish($this->generation);
			$this->callOnFinish();
		}
	}

	private function isNeedExecuteStep(ScenarioStepDto $stepDto): bool
	{
		if (
			$stepDto->status === StepStatuses::Finished
			|| $stepDto->status === StepStatuses::Error
		)
		{
			return false;
		}

		$relations = $this->getNormalizedAsyncRelations();
		if ($relations)
		{
			foreach ($relations as $parent => $dependents)
			{
				if (
					in_array($stepDto->stepId, $dependents, true)
					&& !$this->steps[$parent]?->step->isFinished()
				)
				{
					return false;
				}
			}
		}

		return true;
	}

	private function changeStep(ScenarioStepDto $executedStep): bool
	{
		if (
			$executedStep->step->isFinished()
			|| $executedStep->step->isAsync()
		)
		{
			$newStep = $this->getNextStep($executedStep->stepId);
			if (!$newStep)
			{
				return false;
			}
			if ($newStep > $this->stepId)
			{
				$this->stepId = $newStep;
				$this->callOnStepChange();

				return true;
			}
		}

		return false;
	}

	private function getNextStep(int $stepId): ?int
	{
		$relations = $this->getNormalizedAsyncRelations();
		$currentId = $stepId;
		$visited = [$stepId => true];
		$iterationsLeft = count($this->steps) + 1;
		$lastFinishedCandidate = null;

		while ($iterationsLeft-- > 0)
		{
			$nextId = $this->scenario->getNextStepId($currentId);
			if ($nextId === null || !$this->scenario->checkStep($nextId) || isset($visited[$nextId]))
			{
				return $lastFinishedCandidate;
			}

			$visited[$nextId] = true;
			if (($this->steps[$nextId]?->status ?? null) === StepStatuses::Finished)
			{
				$lastFinishedCandidate = $nextId;
				$currentId = $nextId;
				continue;
			}

			$isBlockedByAsyncParent = false;
			if ($relations)
			{
				foreach ($relations as $parent => $dependents)
				{
					if (
						in_array($nextId, $dependents, true)
						&& !$this->steps[$parent]?->step->isFinished()
					)
					{
						$isBlockedByAsyncParent = true;
						break;
					}
				}
			}

			if (!$isBlockedByAsyncParent)
			{
				return $nextId;
			}

			$currentId = $nextId;
		}

		return $lastFinishedCandidate;
	}

	/**
	 * @return array<int, list<int>>
	 */
	private function getNormalizedAsyncRelations(): array
	{
		if ($this->normalizedAsyncRelations !== null)
		{
			return $this->normalizedAsyncRelations;
		}

		$relations = $this->scenario->getAsyncRelations();
		if (!is_array($relations) || $relations === [])
		{
			$this->normalizedAsyncRelations = [];

			return $this->normalizedAsyncRelations;
		}

		$validStepIds = array_fill_keys(array_keys($this->steps), true);
		$normalized = [];
		foreach ($relations as $parent => $dependents)
		{
			$parentId = (int)$parent;
			if (!isset($validStepIds[$parentId]) || !is_array($dependents))
			{
				continue;
			}

			$normalizedDependents = array_values(array_unique(array_filter(
				array_map('intval', $dependents),
				static fn(int $dependentId): bool => $dependentId > 0 && isset($validStepIds[$dependentId]),
			)));
			if ($normalizedDependents !== [])
			{
				$normalized[$parentId] = $normalizedDependents;
			}
		}

		$this->normalizedAsyncRelations = $normalized;

		return $this->normalizedAsyncRelations;
	}

	/**
	 * Get step DTO for current step ID
	 * @return ScenarioStepDto|null
	 */
	public function getCurrentStep(): ?ScenarioStepDto
	{
		if (!$this->scenario->checkStep($this->stepId))
		{
			return null;
		}

		return $this->steps[$this->stepId];
	}

	/**
	 * Executes a single scenario step, handling quota checks and status updates.
	 *
	 * @param ScenarioStepDto $step The step to execute.
	 *
	 * @return void
	 *
	 * @throws GenerationException If the step fails or a quota is exceeded.
	 */
	private function executeStep(ScenarioStepDto $step): void
	{
		$quotaCalculateStep = $this->scenario->getQuotaCalculateStep();
		if (
			self::isRequestQuotaPreflightEnabled()
			&& $quotaCalculateStep !== null
			&& !$step->step->isStarted()
			&& $step->stepId === $quotaCalculateStep
			&& $this->checkRequestQuotas()
		)
		{
			$requestLimiter = $this->getRequestLimiter();
			$message = $requestLimiter->getCheckResultMessage();
			if (is_string($message))
			{
				throw new GenerationException(
					GenerationErrors::requestQuotaExceeded,
					$message,
					[
						'errorText' => $message,
						'metrikaStatus' => $requestLimiter->getCheckResult()?->getMetrikaStatus(),
					]
				);
			}
		}

		$step->step->execute();

		if ($step->step->isStarted())
		{
			$this->saveStepStatus($step, StepStatuses::Started);
		}

		if ($step->step->isFinished())
		{
			$this->saveStepStatus($step, StepStatuses::Finished);
			if ($step->step->isChanged())
			{
				$this->sendMetrikaStepSuccess($step);
			}
		}
	}

	private static function isRequestQuotaPreflightEnabled(): bool
	{
		return true;
	}

	/**
	 * Saves the status of a scenario step to the database.
	 *
	 * @param ScenarioStepDto $step The step whose status is being saved.
	 * @param StepStatuses $status The new status.
	 *
	 * @return void
	 */
	private function saveStepStatus(ScenarioStepDto $step, StepStatuses $status): void
	{
		$step->status = $status;

		if (!isset($step->entityId))
		{
			$resAdd = StepsTable::add([
				'GENERATION_ID' => $this->generation->getId(),
				'STEP_ID' => $step->stepId,
				'CLASS' => $step->step::class,
				'STATUS' => $status->value,
			]);

			if ($resAdd->isSuccess())
			{
				$step->entityId = $resAdd->getId();
			}

			return;
		}

		StepsTable::update($step->entityId, [
			'STATUS' => $status->value,
		]);
	}

	/**
	 * Checks if the request quota for the scenario is exceeded.
	 *
	 * @return bool True if the request quota is exceeded, false otherwise.
	 */
	private function checkRequestQuotas(): bool
	{
		if (!Loader::includeModule('ai'))
		{
			return false;
		}

		if (FirstSiteGenerationService::isFirstSiteGeneration())
		{
			return false;
		}

		$requestLimiter = $this->getRequestLimiter();

		$isRequestQuotaExceeded = true;
		if (!$requestLimiter->checkQuota($this->getRequestQuotasSum()))
		{
			$isRequestQuotaExceeded = false;
		}

		return $isRequestQuotaExceeded;
	}

	/**
	 * Returns an array of request quotas for all steps in the scenario.
	 *
	 * @return RequestQuotaDto[] Array of request quota DTOs.
	 */
	private function getRequestQuotas(): array
	{
		$quotas = [];
		foreach ($this->steps as $scenarioStep)
		{
			$step = $scenarioStep->step;
			$this->appendQuota(
				$quotas,
				$step::getRequestQuota($this->generation->getSiteData())
					?? $this->getRuntimeRequestQuota($step)
			);
		}

		return $quotas;
	}

	private function getRuntimeRequestQuota(IStep $step): ?RequestQuotaDto
	{
		if (!$step instanceof RuntimeRequestQuotaProvider)
		{
			return null;
		}

		return $step->getRuntimeRequestQuota($this->generation->getSiteData());
	}

	/**
	 * @param array<string, RequestQuotaDto> $quotas
	 */
	private function appendQuota(array &$quotas, ?RequestQuotaDto $stepQuota): void
	{
		if (!$stepQuota)
		{
			return;
		}

		if (isset($quotas[$stepQuota->connectorClass]))
		{
			$quotas[$stepQuota->connectorClass]->requestCount += $stepQuota->requestCount;
		}
		else
		{
			$quotas[$stepQuota->connectorClass] = $stepQuota;
		}
	}

	/**
	 * Returns the sum of all request quotas, ignoring types.
	 *
	 * @return int Total request count.
	 */
	private function getRequestQuotasSum(): int
	{
		$requestCount = 0;
		foreach ($this->getRequestQuotas() as $quota)
		{
			$requestCount += $quota->requestCount;
		}

		return $requestCount;
	}

	/**
	 * Retrieves the RequestLimiter instance, initializing it if not already set.
	 *
	 * @return RequestLimiter
	 */
	private function getRequestLimiter(): RequestLimiter
	{
		if (!isset($this->requestLimiter))
		{
			$this->requestLimiter = new RequestLimiter();
		}

		return $this->requestLimiter;
	}

	/**
	 * Sends a Metrika analytics event for the start of the scenario.
	 *
	 * @return void
	 */
	private function sendMetrikaStart(): void
	{
		$metrika = $this->generation->getMetrika(Metrika\Events::start);
		$metrika->send();
	}

	/**
	 * Sends a Metrika analytics event for a successful step execution.
	 *
	 * @param ScenarioStepDto $step The step for which to send analytics.
	 *
	 * @return void
	 */
	private function sendMetrikaStepSuccess(ScenarioStepDto $step): void
	{
		$event = $step->step->getAnalyticEvent();
		if (isset($event))
		{
			$metrika = $this->generation->getMetrika($event);
			$metrika->send();
		}
	}
}
