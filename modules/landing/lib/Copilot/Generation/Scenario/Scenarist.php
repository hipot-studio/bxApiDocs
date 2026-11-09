<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Scenario;

use Bitrix\Landing\Copilot\Connector;
use Bitrix\Landing\Copilot\Connector\AI\RequestLimiter;
use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Step\Base\IStep;
use Bitrix\Landing\Copilot\Generation\Step\Base\RuntimeRequestQuotaProvider;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Copilot\Generation\GenerationAbortedException;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\GenerationTimeSliceException;
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
	private const EVENT_STEP = 'onStepExecute';

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
	 * @var bool Whether every Error status set on a step has also reached the database. Flipped by
	 *   markStepErroredSafely() when it suppresses a failed write, and never flipped back: a run that
	 *   could not persist the mark must keep an executor for the generation.
	 */
	private bool $erroredStepMarkPersisted = true;

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
	 * Aborts the scenario: the first not-finished step gets the error status, so the
	 * generation reads as failed and neither execute() nor the retry agent resumes it.
	 *
	 * The status goes through markStepErroredSafely(): a mark that did not reach the database
	 * leaves the step executable for the next run, so it is no abort.
	 *
	 * The abort takes no lock of the generation: a run that holds it at that moment finds the mark
	 * through isAbortedOutside() before any status write of its own and ends with it.
	 *
	 * @return bool False when there is nothing to abort (finished or already stopped) or when
	 *   the Error status could not be persisted.
	 */
	public function abort(): bool
	{
		if ($this->isFinished() || $this->isError())
		{
			return false;
		}

		foreach ($this->steps as $step)
		{
			if ($step->status !== StepStatuses::Finished)
			{
				return $this->markStepErroredSafely($step);
			}
		}

		return false;
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
	 * Whether the errored step is terminal for everyone, not just for this process: the Error status
	 * is both set in memory and written to the database.
	 *
	 * The two differ exactly when the status write has been suppressed by markStepErroredSafely().
	 * The step object then says Error while the database still says the step is executable, so the
	 * next run would load it as unfinished. A caller that decides whether the generation still needs
	 * an executor must ask this, not isError(): dropping the executor over a mark that was never
	 * written is what leaves a generation running with nothing to continue it.
	 *
	 * @return bool True if a step is errored and its Error status has been persisted.
	 */
	public function isErrorPersisted(): bool
	{
		return $this->isError() && $this->erroredStepMarkPersisted;
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
	 * Any step exception except the cooperative time budget exit marks the step as errored and is rethrown as is.
	 *
	 * @return void
	 *
	 * @throws GenerationException If a quota is exceeded.
	 * @throws GenerationTimeSliceException If a step exits by the execution time budget.
	 * @throws \Throwable If a step fails.
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

		if ($this->stepId === $this->scenario->getFirstStepId() && $this->scenario->isAnalyticStartEnabled())
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
			catch (GenerationTimeSliceException $e)
			{
				// cooperative exit by the time budget: the step is still executable, do not mark it as errored
				if ($this->isAbortedDuringPause())
				{
					throw new GenerationAbortedException();
				}

				throw $e;
			}
			catch (GenerationAbortedException $e)
			{
				// the abort of another process: its Error mark is already adopted, nothing to mark here
				throw $e;
			}
			catch (\Throwable $e)
			{
				$this->markStepErroredSafely($stepDto);

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

		$isStarted = $step->step->isStarted();
		$isFinished = $step->step->isFinished();
		if (!$isStarted && !$isFinished)
		{
			return;
		}

		// The statuses below go over whatever the row holds now, and while the step ran another process
		// may have aborted the generation (Generation::cancel()) by writing the Error status to that very
		// row. A status of the run written over it would continue a cancelled generation, so the mark is
		// read back first and adopted instead.
		if ($this->isAbortedOutside())
		{
			throw new GenerationAbortedException();
		}

		if ($isStarted)
		{
			$this->saveStepStatus($step, StepStatuses::Started);
		}

		if ($isFinished)
		{
			$this->saveStepStatus($step, StepStatuses::Finished);
			if ($step->step->isChanged())
			{
				$this->sendMetrikaStepSuccess($step);
			}
		}
	}

	/**
	 * Whether another process has aborted the generation while this run holds it: a step row of the
	 * database carries the Error status that this run never set. The abort (Generation::cancel()) runs
	 * without the lock of the generation, so the running process is the only one that can notice it,
	 * and it has to read the row back to do so.
	 *
	 * A found mark is adopted: the step reads as errored and persisted from here on, exactly as if
	 * the run had written the mark itself, so isErrorPersisted() makes the end of the run terminal.
	 * The row of a step that had none yet - an abort of a step not started - becomes the row of the
	 * step, or the next status write would add a second row next to the mark. Adopted once: a mark
	 * that is already the run's own answers false.
	 *
	 * @return bool True when a mark of an abort has just been found and adopted.
	 */
	public function isAbortedOutside(): bool
	{
		$generationId = (int)($this->generation->getId() ?? 0);
		if ($generationId <= 0)
		{
			return false;
		}

		$isAdopted = false;
		foreach ($this->readStepRows($generationId) as $row)
		{
			$step = $this->steps[(int)($row['STEP_ID'] ?? 0)] ?? null;
			if (
				$step === null
				|| (int)($row['STATUS'] ?? -1) !== StepStatuses::Error->value
				|| $step->status === StepStatuses::Error
			)
			{
				continue;
			}

			$step->status = StepStatuses::Error;
			$step->entityId = (int)$row['ID'];
			$isAdopted = true;
		}

		return $isAdopted;
	}

	/**
	 * The pause by the time budget sets the agent of the generation, and an abort that landed during the
	 * run would leave that agent an errored generation that nothing ends: the chat stays busy and the
	 * cancel is never recorded. So the mark is read back before the pause lets go. A failed read is no
	 * abort: the pause travels on as it is, it must not turn into a failure of the run.
	 */
	private function isAbortedDuringPause(): bool
	{
		try
		{
			return $this->isAbortedOutside();
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	/**
	 * Storage seam of the abort read: the rows of the steps of the generation as the database holds
	 * them now. Kept apart from isAbortedOutside() for the same reason the writes are: the rule about
	 * which stored mark is adopted is observable without a database.
	 *
	 * @return list<array{ID: int, STEP_ID: int, STATUS: int}>
	 */
	protected function readStepRows(int $generationId): array
	{
		return StepsTable::query()
			->setSelect(['ID', 'STEP_ID', 'STATUS'])
			->where('GENERATION_ID', $generationId)
			->exec()
			->fetchAll()
		;
	}

	private static function isRequestQuotaPreflightEnabled(): bool
	{
		return true;
	}

	/**
	 * Marks the step as errored, suppressing any failure of the status write itself.
	 *
	 * The write goes to the database and may fail on exactly the same class of problems (deadlock, lost connection)
	 * that has just broken the step. Such a failure must neither replace the original step exception nor stop it
	 * from being rethrown.
	 *
	 * A suppressed write is the only case where the step stays "running" forever, so the reason is written to the
	 * generation log before it is swallowed: without that entry the failure leaves no trace at all.
	 *
	 * @param ScenarioStepDto $step The step to mark.
	 *
	 * @return bool True when the Error status has reached the database.
	 */
	private function markStepErroredSafely(ScenarioStepDto $step): bool
	{
		try
		{
			if (!$this->saveStepStatus($step, StepStatuses::Error))
			{
				// A storage that refuses the write without throwing leaves exactly the same state as one
				// that throws - the mark on the step object only - so it must be read the same way.
				$this->erroredStepMarkPersisted = false;

				return false;
			}

			return true;
		}
		catch (\Throwable $writeException)
		{
			// The mark stays in memory only: isErrorPersisted() answers false from here on, so the
			// generation keeps an executor and the step is marked again on the next run.
			$this->erroredStepMarkPersisted = false;

			// the original step exception is the one that must reach the generation exception handler
			$this->logErroredStepMarkFailure($step, $writeException);

			return false;
		}
	}

	/**
	 * Internal-only trace of a suppressed Error-status write: generation id, step id and the class of the write
	 * exception. The exception message is deliberately left out - it may carry SQL text - and nothing here changes
	 * how the original step exception travels.
	 *
	 * @param ScenarioStepDto $step The step whose status write has failed.
	 * @param \Throwable $writeException The failure of the status write itself.
	 *
	 * @return void
	 */
	private function logErroredStepMarkFailure(ScenarioStepDto $step, \Throwable $writeException): void
	{
		try
		{
			if (!Generation\Log::isEnabled())
			{
				return;
			}

			$generationId = (int)($this->generation->getId() ?? 0);

			(new Generation\Log($generationId))->add(sprintf(
				'Failed to mark step as errored: generationId=%d, stepId=%d, writeException=%s',
				$generationId,
				(int)$step->stepId,
				$writeException::class,
			));
		}
		catch (\Throwable)
		{
			// logging must never outweigh the original step exception
		}
	}

	/**
	 * Saves the status of a scenario step to the database.
	 *
	 * The status is set on the step object first and always, and the answer tells whether it also
	 * reached the storage: a write that fails without throwing - an unsuccessful Result - leaves the
	 * step executable in the database exactly as a thrown one does, and the caller of the Error status
	 * has to know the difference.
	 *
	 * @param ScenarioStepDto $step The step whose status is being saved.
	 * @param StepStatuses $status The new status.
	 *
	 * @return bool True when the status reached the storage.
	 */
	private function saveStepStatus(ScenarioStepDto $step, StepStatuses $status): bool
	{
		$step->status = $status;

		if (!isset($step->entityId))
		{
			$entityId = $this->addStepRow(
				(int)$this->generation->getId(),
				$step->stepId,
				$step->step::class,
				$status,
			);
			if ($entityId === null)
			{
				return false;
			}

			$step->entityId = $entityId;

			return true;
		}

		return $this->updateStepRow($step->entityId, $status);
	}

	/**
	 * Storage seam of the step status write: persists a new step row and answers with its id, or null
	 * when the row could not be added.
	 *
	 * Kept apart from saveStepStatus() so that the rule above - which status is written, for which step,
	 * and whether it is written at all - can be observed without a database. The Error status of a failed
	 * step is the only thing standing between a broken step and a generation that stays running forever,
	 * so the fact of the write is worth asserting on its own.
	 *
	 * @param int $generationId Owner of the step row.
	 * @param int $stepId Scenario step id.
	 * @param string $stepClass Class of the step, stored for diagnostics.
	 * @param StepStatuses $status Status to persist.
	 *
	 * @return int|null Id of the new row, or null when it was not added.
	 */
	protected function addStepRow(int $generationId, int $stepId, string $stepClass, StepStatuses $status): ?int
	{
		$resAdd = StepsTable::add([
			'GENERATION_ID' => $generationId,
			'STEP_ID' => $stepId,
			'CLASS' => $stepClass,
			'STATUS' => $status->value,
		]);

		return $resAdd->isSuccess() ? (int)$resAdd->getId() : null;
	}

	/**
	 * Storage seam of the step status write over an already persisted step row. Answers with the fact
	 * of the write for the same reason addStepRow() does: an unsuccessful Result is a step that stayed
	 * executable in the database, and reading it as a written mark is what declares a still runnable
	 * generation terminal.
	 *
	 * @param int $entityId Row of the step.
	 * @param StepStatuses $status Status to persist.
	 *
	 * @return bool True when the row was moved to the status.
	 */
	protected function updateStepRow(int $entityId, StepStatuses $status): bool
	{
		return StepsTable::update($entityId, [
			'STATUS' => $status->value,
		])->isSuccess();
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
