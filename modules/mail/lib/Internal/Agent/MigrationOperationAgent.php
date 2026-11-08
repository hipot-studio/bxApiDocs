<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Agent;

use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationCancelService;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationMetrics;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationService;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationStage;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationUserStatusPublisher;
use Bitrix\Mail\Internal\Service\SourceGeneration\OperationTracker;
use Bitrix\Mail\Internal\Service\SourceGeneration\RetryPolicy;
use Bitrix\Mail\Internal\Service\SourceGeneration\RolloutBrakeReader;
use Bitrix\Mail\Integration\MailService\MigrationStatusProvider;
use Bitrix\Mail\Internals\MigrationOperationTable;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

final class MigrationOperationAgent
{
	public const BATCH_SIZE = 20;
	public const TIME_BUDGET_SECONDS = 60;
	public const PERIOD_SECONDS = 60;

	public function __construct(
		private readonly OperationTracker $tracker = new OperationTracker(),
		private readonly MigrationService $migrationService = new MigrationService(),
		private readonly MigrationCancelService $cancelService = new MigrationCancelService(),
		private readonly RetryPolicy $retryPolicy = new RetryPolicy(),
		private readonly MigrationStatusProvider $statusProvider = new MigrationStatusProvider(),
		private readonly MigrationUserStatusPublisher $statusPublisher = new MigrationUserStatusPublisher(),
		private readonly RolloutBrakeReader $rolloutBrakeReader = new RolloutBrakeReader(),
		private readonly ?DateTime $fixedNow = null,
	)
	{
	}

	public static function run(): string
	{
		(new self())->execute();

		return self::class . '::run();';
	}

	public function execute(): void
	{
		$startedAt = microtime(true);
		try
		{
			$pendingPublications = $this->tracker->pendingPublications(self::BATCH_SIZE);
		}
		catch (\Throwable)
		{
			MigrationMetrics::journalOperation(
				'publication scheduler failed',
				0,
				'-',
				0,
				null,
				RetryPolicy::ERROR_SCHEDULER_EXCEPTION,
			);
			$pendingPublications = [];
		}
		foreach ($pendingPublications as $operation)
		{
			$this->flushPublication($operation);
		}

		try
		{
			if ($this->rolloutBrakeReader->findRefusalNow() === null)
			{
				$this->tracker->wakeStoppedOperations(self::BATCH_SIZE, $this->now());
			}
		}
		catch (\Throwable)
		{
			MigrationMetrics::journalOperation(
				'operation wake failed',
				0,
				'-',
				0,
				null,
				RetryPolicy::ERROR_SCHEDULER_EXCEPTION,
			);
		}

		try
		{
			$ready = $this->tracker->ready(self::BATCH_SIZE, $this->now());
		}
		catch (\Throwable)
		{
			MigrationMetrics::journalOperation(
				'operation scheduler failed',
				0,
				'-',
				0,
				null,
				RetryPolicy::ERROR_SCHEDULER_EXCEPTION,
			);

			return;
		}

		foreach ($ready as $operation)
		{
			if (microtime(true) - $startedAt >= self::TIME_BUDGET_SECONDS)
			{
				break;
			}

			$this->process($operation);
		}
	}

	private function process(array $operation): void
	{
		$publicStatusBeforeClaim = $this->publicStatusOf($operation);
		try
		{
			$operation = $this->tracker->claim($operation, $this->now());
		}
		catch (\Throwable)
		{
			$this->journalSchedulerFailure($operation, 'operation claim failed');

			return;
		}
		if ($operation === null)
		{
			return;
		}

		$mailboxId = (int)$operation['MAILBOX_ID'];
		$operationId = (string)$operation['OPERATION_ID'];
		$stage = MigrationStage::tryFrom((string)$operation['STAGE']) ?? MigrationStage::Legacy;

		if ((string)$operation['EXEC_STATE'] === MigrationOperationTable::STATE_CANCELLING)
		{
			$this->processCancellation($operation, $stage, $publicStatusBeforeClaim);

			return;
		}

		MigrationMetrics::journalOperation(
			'agent pass started',
			$mailboxId,
			$operationId,
			(int)$operation['GENERATION_ID'],
			$stage->value,
		);

		try
		{
			$outcome = $this->migrationService->run($mailboxId, $operationId);
		}
		catch (\Throwable)
		{
			$outcome = new Result();
			$outcome->addError(new \Bitrix\Main\Error(
				'The migration agent pass failed',
				RetryPolicy::ERROR_AGENT_EXCEPTION,
			));
		}

		$this->recordPass($operation, $outcome, $stage, $publicStatusBeforeClaim);
	}

	private function processCancellation(
		array $operation,
		MigrationStage $stage,
		?array $publicStatusBeforeClaim,
	): void
	{
		try
		{
			$outcome = $this->cancelService->step(
				(int)$operation['MAILBOX_ID'],
				(string)$operation['OPERATION_ID'],
			);
		}
		catch (\Throwable)
		{
			$outcome = new Result();
			$outcome->addError(new \Bitrix\Main\Error(
				'The migration cancellation pass failed',
				RetryPolicy::ERROR_AGENT_EXCEPTION,
			));
		}

		$outcomeStage = $outcome->getData()['stage'] ?? $stage;
		if (!$outcomeStage instanceof MigrationStage)
		{
			$outcomeStage = $stage;
		}

		if ($outcome->isSuccess() && (bool)($outcome->getData()['completed'] ?? false))
		{
			try
			{
				$stored = $this->tracker->markCancelled(
					(int)$operation['MAILBOX_ID'],
					(string)$operation['OPERATION_ID'],
					$outcomeStage,
					publish: true,
					notify: true,
				);
			}
			catch (\Throwable)
			{
				$stored = false;
			}
			if (!$stored)
			{
				$this->journalSchedulerFailure($operation, 'cancelled state was not stored');

				return;
			}
			$this->flushPublication($operation);
			MigrationMetrics::journalOperation(
				'cancelled',
				(int)$operation['MAILBOX_ID'],
				(string)$operation['OPERATION_ID'],
				(int)$operation['GENERATION_ID'],
				$outcomeStage->value,
			);

			return;
		}

		$errorCode = $this->firstErrorCode($outcome);
		$decision = $this->retryPolicy->decide(
			$operation,
			$outcomeStage,
			$errorCode,
			(bool)($outcome->getData()['progressed'] ?? false),
			$this->now(),
		);
		if ($decision['execState'] !== MigrationOperationTable::STATE_NEEDS_ATTENTION)
		{
			$decision['execState'] = MigrationOperationTable::STATE_CANCELLING;
		}

		$publish = $this->publicStatusAfterDecision($operation, $decision) !== $publicStatusBeforeClaim;
		if (!$this->storeOutcome($operation, $decision, $publish))
		{
			return;
		}
		if ($publish)
		{
			$this->flushPublication($operation);
		}
		MigrationMetrics::journalOperation(
			'cancellation pass completed',
			(int)$operation['MAILBOX_ID'],
			(string)$operation['OPERATION_ID'],
			(int)$operation['GENERATION_ID'],
			$outcomeStage->value,
			$errorCode,
		);
	}

	private function recordPass(
		array $operation,
		Result $outcome,
		MigrationStage $fallbackStage,
		?array $publicStatusBeforeClaim,
	): void
	{
		$outcomeData = $outcome->getData();
		$stage = $outcomeData['stage'] ?? $fallbackStage;
		if (!$stage instanceof MigrationStage)
		{
			$stage = $fallbackStage;
		}

		$errorCode = $this->firstErrorCode($outcome);
		$retryResumedAt = $outcomeData['manualRetryResumedAt'] ?? null;
		$leftTheRolloutPause = !$retryResumedAt instanceof DateTime
			&& $this->leftTheRolloutPause($operation, $errorCode);
		if ($leftTheRolloutPause)
		{
			$retryResumedAt = $this->now();
		}

		$decision = $this->retryPolicy->decide(
			$operation,
			$stage,
			$errorCode,
			(bool)($outcomeData['progressed'] ?? false),
			$this->now(),
			(bool)($outcomeData['manualWaiting'] ?? false),
			$retryResumedAt,
			(bool)($outcomeData['manualRetryAttemptsReset'] ?? false),
		);
		if ($leftTheRolloutPause)
		{
			/*
				The moment of the release is written down and not only used for this one pass.
				A pass that made no progress stores the answer of the policy as it stands, so an
				end of a pause kept in memory alone would be forgotten by the next pass: it would
				count the budget from before the pause again and hand the operation to a human
				after all. The manual control of the stages keeps such a mark in a row of its own
				{@see ManualMigrationControl}; the operations of the transfer have no field for it
				and no need of one - the moment the transfer is allowed to move again is exactly
				what the budget of the retries counts from.
			*/
			$decision['lastProgress'] = $retryResumedAt;
			$decision['stalledAttempts'] = 0;
		}

		$decision['manualVersion'] = array_key_exists('manualVersion', $outcomeData)
			? $outcomeData['manualVersion']
			: ($operation['MANUAL_VERSION'] ?? null);
		$decision['consumeManualRetryResume'] = ($outcomeData['manualRetryResumedAt'] ?? null) instanceof DateTime
			&& ((bool)($outcomeData['progressed'] ?? false) || !$decision['autoRetry']);

		$publish = $this->publicStatusAfterDecision($operation, $decision) !== $publicStatusBeforeClaim;
		if (!$this->storeOutcome(
			$operation,
			$decision,
			$publish,
			$decision['execState'] === MigrationOperationTable::STATE_DONE,
		))
		{
			return;
		}
		if ($publish)
		{
			$this->flushPublication($operation);
		}
		MigrationMetrics::journalOperation(
			$decision['execState'] === MigrationOperationTable::STATE_DONE
				? 'completed'
				: 'agent pass completed',
			(int)$operation['MAILBOX_ID'],
			(string)$operation['OPERATION_ID'],
			(int)$operation['GENERATION_ID'],
			$stage->value,
			$decision['errorCode'],
		);
	}

	/**
	 * Whether this pass is the first one after a pause of the rollout: the switches of the
	 * portal were holding the operation, and now they are not.
	 *
	 * It matters because of what such a pause does to the budget of the retries. A transfer
	 * that made no progress for a day is taken for a stuck one and handed to a human - and a
	 * portal stopped by the emergency brake for a day made no progress by definition. So the budget is counted from the end of the pause, exactly as the manual
	 * control of the stages counts it from the moment the operator lets the transfer go on
	 * {@see ManualMigrationControl}.
	 *
	 * The pause itself costs the operation nothing else: while it lasts, every pass is refused
	 * at the entry and the refusal is classified as a wait {@see RetryPolicy::classifyAccepted()},
	 * so neither the attempts nor the stage move.
	 */
	private function leftTheRolloutPause(array $operation, ?string $errorCode): bool
	{
		$pausedBy = [MigrationService::ERROR_MIGRATION_STOPPED, MigrationService::ERROR_FEATURE_DISABLED];

		return in_array((string)($operation['LAST_ERROR_CODE'] ?? ''), $pausedBy, true)
			&& !in_array((string)$errorCode, $pausedBy, true);
	}

	private function firstErrorCode(Result $result): ?string
	{
		$error = $result->getErrors()[0] ?? null;

		return $error === null ? null : (string)$error->getCode();
	}

	private function publicStatusOf(array $operation): ?array
	{
		return $this->statusProvider->fromRow($operation)?->toPublicArray();
	}

	private function publicStatusAfterDecision(array $operation, array $decision): ?array
	{
		return $this->publicStatusOf(array_replace($operation, [
			'EXEC_STATE' => $decision['execState'],
			'STAGE' => $decision['stage']->value,
			'AUTO_RETRY' => $decision['autoRetry'] ? 'Y' : 'N',
			'STALLED_ATTEMPTS' => $decision['stalledAttempts'],
			'NEXT_RUN' => $decision['nextRun'],
			'LAST_PROGRESS' => $decision['lastProgress'],
			'LAST_ERROR_CODE' => $decision['errorCode'],
			'ERROR_OWNER' => $decision['errorOwner'],
		]));
	}

	private function storeOutcome(
		array $operation,
		array $decision,
		bool $publish,
		bool $notify = false,
	): bool
	{
		try
		{
			$stored = $this->tracker->recordOutcome(
				$operation,
				$decision,
				$publish,
				$notify,
				$decision['manualVersion'] ?? null,
				(bool)($decision['consumeManualRetryResume'] ?? false),
			);
		}
		catch (\Throwable)
		{
			$stored = false;
		}

		if (!$stored)
		{
			$this->journalSchedulerFailure($operation, 'operation outcome was not stored');
		}

		return $stored;
	}

	private function flushPublication(array $operation): void
	{
		try
		{
			$published = $this->tracker->publishPending(
				(int)$operation['MAILBOX_ID'],
				(string)$operation['OPERATION_ID'],
				$this->statusPublisher,
			);
		}
		catch (\Throwable)
		{
			$published = false;
		}

		if (!$published)
		{
			$this->journalSchedulerFailure($operation, 'status publication failed');
		}
	}

	private function journalSchedulerFailure(array $operation, string $event): void
	{
		MigrationMetrics::journalOperation(
			$event,
			(int)($operation['MAILBOX_ID'] ?? 0),
			(string)($operation['OPERATION_ID'] ?? '-'),
			(int)($operation['GENERATION_ID'] ?? 0),
			(string)($operation['STAGE'] ?? MigrationStage::Legacy->value),
			RetryPolicy::ERROR_SCHEDULER_EXCEPTION,
		);
	}

	private function now(): DateTime
	{
		return $this->fixedNow === null ? new DateTime() : clone $this->fixedNow;
	}
}
