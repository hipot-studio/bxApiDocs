<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallScriptMaintenance;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Main\Application;

final class Orchestrator
{
	private const DATETIME_FORMAT = 'Y-m-d H:i:s';
	private const TICK_LOCK_NAME = 'crm_call_script_maintenance_tick';

	private readonly StateRepository $stateRepo;
	private readonly CandidatesRepository $candidates;
	private readonly Dispatcher $dispatcher;

	public function __construct()
	{
		$this->stateRepo = new StateRepository();
		$this->candidates = new CandidatesRepository();
		$this->dispatcher = Dispatcher::getInstance();
	}

	public function tick(): int
	{
		$connection = Application::getConnection();
		if (!$connection->lock(self::TICK_LOCK_NAME))
		{
			return Config::getStepIntervalSeconds();
		}

		$state = $this->stateRepo->load();
		try
		{
			$this->dispatch($state);
		}
		finally
		{
			$this->stateRepo->save($state);
			$connection->unlock(self::TICK_LOCK_NAME);
		}

		return $this->calculateNextExecDelaySeconds($state);
	}

	private function calculateNextExecDelaySeconds(State $state): int
	{
		$step = Config::getStepIntervalSeconds();

		if ($state->currentStep === State::STEP_IDLE)
		{
			if ($state->lastCycleCompletedAt === null)
			{
				return $step;
			}

			$period = Config::getCyclePeriodSeconds();
			$elapsed = time() - (int)strtotime($state->lastCycleCompletedAt);
			$remaining = $period - $elapsed;

			return max($step, $remaining);
		}

		if ($state->stepState === State::SUBSTATE_AWAITING_AI)
		{
			return Config::getAiPollIntervalSeconds();
		}

		$retryDelay = $this->getAiRetryBackoffRemainingSeconds($state);
		if ($retryDelay > 0)
		{
			return max($step, $retryDelay);
		}

		return $step;
	}

	private function getAiRetryBackoffRemainingSeconds(State $state): int
	{
		if ($state->aiRetryFailureCount <= 0 || $state->aiRetryLastFailureAt === null)
		{
			return 0;
		}

		$base = max(1, Config::getStepIntervalSeconds());
		$cap = max($base, Config::getCyclePeriodSeconds());
		$exp = min(30, $state->aiRetryFailureCount);
		$delay = min($cap, $base * (1 << $exp));

		$lastFailure = (int)strtotime($state->aiRetryLastFailureAt);
		if ($lastFailure <= 0)
		{
			return 0;
		}

		return max(0, $delay - (time() - $lastFailure));
	}

	private function dispatch(State $state): void
	{
		if ($state->currentStep === State::STEP_IDLE)
		{
			$this->startCycle($state);
		}

		if ($state->stepState === State::SUBSTATE_AWAITING_AI)
		{
			$this->syncAwaitingAi($state);

			return;
		}

		if ($this->shouldBackoffAiRetry($state))
		{
			return;
		}

		if ($state->currentStep === State::STEP_GROUPING)
		{
			$this->runGroupingStep($state);

			return;
		}

		if ($state->currentStep === State::STEP_ENRICHMENT)
		{
			$this->runEnrichmentStep($state);
		}
	}

	private function shouldBackoffAiRetry(State $state): bool
	{
		$remaining = $this->getAiRetryBackoffRemainingSeconds($state);
		if ($remaining <= 0)
		{
			return false;
		}

		AIManager::logger()->info(
			'{date}: {class}: ai retry backoff active — {remaining}s left (failures={count})',
			['class' => self::class, 'remaining' => $remaining, 'count' => $state->aiRetryFailureCount],
		);

		return true;
	}

	private function recordAiRetryFailure(State $state, string $stage): void
	{
		$state->aiRetryFailureCount++;
		$state->aiRetryLastFailureAt = date(self::DATETIME_FORMAT);

		AIManager::logger()->error(
			'{date}: {class}: ai retry failure recorded ({stage}); failure #{count}, backing off next ticks',
			['class' => self::class, 'stage' => $stage, 'count' => $state->aiRetryFailureCount],
		);
	}

	private function clearAiRetryState(State $state): void
	{
		if ($state->aiRetryFailureCount === 0 && $state->aiRetryLastFailureAt === null)
		{
			return;
		}

		$state->aiRetryFailureCount = 0;
		$state->aiRetryLastFailureAt = null;
	}

	private function startCycle(State $state): void
	{
		$this->stateRepo->deleteExpiredContext(Config::getContextTtlSeconds());

		$state->currentStep = State::STEP_GROUPING;
		$state->stepState = State::SUBSTATE_READY;

		AIManager::logger()->info(
			'{date}: {class}: cycle started',
			['class' => self::class],
		);
	}

	private function syncAwaitingAi(State $state): void
	{
		$jobId = (int)($state->awaitingJobId ?? 0);
		if ($jobId <= 0)
		{
			$state->stepState = State::SUBSTATE_READY;

			return;
		}

		if ($this->isAwaitingTimedOut($state))
		{
			AIManager::logger()->warning(
				'{date}: {class}: ai job {job} wait timed out (awaiting since {since}) — releasing state',
				['class' => self::class, 'job' => $jobId, 'since' => $state->awaitingSince],
			);
			$this->recordAiRetryFailure($state, 'job-TIMEOUT');
			$this->releaseAwaitingState($state);

			return;
		}

		$status = $this->candidates->aiQueueStatus($jobId);
		if ($status === CandidatesRepository::AI_QUEUE_STATUS_PENDING)
		{
			return;
		}

		if ($status === CandidatesRepository::AI_QUEUE_STATUS_SUCCESS)
		{
			AIManager::logger()->info(
				'{date}: {class}: ai job {job} already SUCCESS — moving on',
				['class' => self::class, 'job' => $jobId],
			);
			$this->clearAiRetryState($state);
		}
		else
		{
			AIManager::logger()->warning(
				'{date}: {class}: ai job {job} status {status} — moving on',
				['class' => self::class, 'job' => $jobId, 'status' => $status],
			);
			$this->recordAiRetryFailure($state, 'job-' . $status);
		}

		$this->releaseAwaitingState($state);
	}

	private function isAwaitingTimedOut(State $state): bool
	{
		if ($state->awaitingSince === null)
		{
			return false;
		}

		$awaitingStart = (int)strtotime($state->awaitingSince);
		if ($awaitingStart <= 0)
		{
			return false;
		}

		return (time() - $awaitingStart) >= Config::getAiWaitTimeoutSeconds();
	}

	private function releaseAwaitingState(State $state): void
	{
		$state->stepState = State::SUBSTATE_READY;
		$state->awaitingJobId = null;
		$state->awaitingSince = null;

		if ($state->currentStep === State::STEP_GROUPING)
		{
			$state->currentStep = State::STEP_ENRICHMENT;
		}
	}

	private function runGroupingStep(State $state): void
	{
		$threshold = Config::getConfidenceThreshold();

		$total = $this->candidates->countSuspiciousNotGrouped($threshold);
		if ($total < Config::getGroupingMinCalls())
		{
			$state->currentStep = State::STEP_ENRICHMENT;
			$state->stepState = State::SUBSTATE_READY;

			return;
		}

		$batch = $this->candidates->loadSuspiciousBatch(
			$threshold,
			Config::getGroupingMaxCallsPerRun(),
		);

		if (empty($batch))
		{
			$state->currentStep = State::STEP_ENRICHMENT;
			$state->stepState = State::SUBSTATE_READY;

			return;
		}

		[
			'calls' => $calls,
			'selectionIds' => $selectionIds,
		] = $this->buildGroupingPayload($batch);

		$callAssessments = $this->candidates->loadCallAssessments();
		if (empty($callAssessments))
		{
			AIManager::logger()->info(
				'{date}: {class}: no enabled call assessments — ending cycle until next maintenance window',
				['class' => self::class],
			);
			$state->resetToIdle(date(self::DATETIME_FORMAT));

			return;
		}

		$result = AIManager::launchGroupSuspiciousCalls($calls, $callAssessments, Dispatcher::getSystemUserId());

		$jobId = (int)($result->getJobId() ?? 0);
		if ($jobId <= 0)
		{
			$this->recordAiRetryFailure($state, 'grouping-launch');

			return;
		}

		$this->stateRepo->registerMaintenanceJob($jobId, [
			'type' => State::JOB_TYPE_GROUPING,
			'selectionIds' => $selectionIds,
		]);

		$state->stepState = State::SUBSTATE_AWAITING_AI;
		$state->awaitingJobId = $jobId;
		$state->awaitingSince = date(self::DATETIME_FORMAT);
	}

	private function buildGroupingPayload(array $batch): array
	{
		$calls = [];
		$selectionIds = [];
		$seenActivityIds = [];

		foreach ($batch as $row)
		{
			$activityId = $row['activityId'];
			$selectionIds[] = $row['selectionId'];

			if (isset($seenActivityIds[$activityId]))
			{
				continue;
			}

			$seenActivityIds[$activityId] = true;
			$calls[] = [
				'id' => $activityId,
				'data' => [
					'theme' => $row['theme'],
					'product' => $row['product'],
					'intent' => $row['intent'],
				],
			];
		}

		return [
			'calls' => $calls,
			'selectionIds' => $selectionIds,
		];
	}

	private function runEnrichmentStep(State $state): void
	{
		$threshold = Config::getConfidenceThreshold();

		if (empty($state->enrichmentPendingCallAssessmentIds) && $state->enrichmentCurrentCallAssessmentId === null)
		{
			$this->candidates->markUnenrichableSelections($threshold);
			$candidates = $this->candidates->findCallAssessmentsWithEnoughEnrichmentCandidates(
				$threshold,
				Config::getEnrichmentMinCalls(),
			);

			if (empty($candidates))
			{
				$state->resetToIdle(date(self::DATETIME_FORMAT));
				AIManager::logger()->info(
					'{date}: {class}: cycle completed',
					['class' => self::class],
				);

				return;
			}

			$state->enrichmentPendingCallAssessmentIds = $candidates;
		}

		if ($state->enrichmentCurrentCallAssessmentId === null)
		{
			$state->enrichmentCurrentCallAssessmentId = array_shift($state->enrichmentPendingCallAssessmentIds);
		}

		$callAssessmentId = (int)($state->enrichmentCurrentCallAssessmentId ?? 0);
		if ($callAssessmentId <= 0)
		{
			$state->enrichmentCurrentCallAssessmentId = null;
			$state->resetToIdle(date(self::DATETIME_FORMAT));

			return;
		}

		$rows = $this->candidates->loadSelectionIdsForCallAssessment($callAssessmentId, $threshold, Config::getEnrichmentBatchSize());
		if (empty($rows))
		{
			$state->enrichmentCurrentCallAssessmentId = null;

			return;
		}

		$selectionsByActivity = [];
		foreach ($rows as $r)
		{
			$selectionsByActivity[$r['activityId']][] = $r['selectionId'];
		}
		$uniqueActivityIds = array_keys($selectionsByActivity);

		$dialogues = $this->dispatcher->loadDialogues($uniqueActivityIds);
		$usedActivityIds = array_map(static fn ($d) => (int)$d['id'], $dialogues);

		$droppedActivityIds = array_values(array_diff($uniqueActivityIds, $usedActivityIds));
		if (!empty($droppedActivityIds))
		{
			$droppedSelectionIds = [];
			foreach ($droppedActivityIds as $aid)
			{
				foreach ($selectionsByActivity[$aid] ?? [] as $sid)
				{
					$droppedSelectionIds[] = $sid;
				}
			}
			if (!empty($droppedSelectionIds))
			{
				$this->dispatcher->markEnriched($droppedSelectionIds);
			}
		}

		if (empty($dialogues))
		{
			return;
		}

		$usedSelectionIds = [];
		foreach ($usedActivityIds as $aid)
		{
			foreach ($selectionsByActivity[$aid] ?? [] as $sid)
			{
				$usedSelectionIds[] = $sid;
			}
		}

		$jobId = $this->dispatcher->launchEnrichCallAssessmentJob($callAssessmentId, array_column($dialogues, 'transcript'));
		if ($jobId <= 0)
		{
			$this->recordAiRetryFailure($state, 'enrichment-launch');

			return;
		}

		$this->stateRepo->registerMaintenanceJob($jobId, [
			'type' => State::JOB_TYPE_ENRICH_CALL_ASSESSMENT,
			'selectionIds' => $usedSelectionIds,
			'assessmentId' => $callAssessmentId,
		]);

		$state->awaitingJobId = $jobId;
		$state->stepState = State::SUBSTATE_AWAITING_AI;
		$state->awaitingSince = date(self::DATETIME_FORMAT);
	}
}
