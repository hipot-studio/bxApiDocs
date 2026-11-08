<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\MailService;

use Bitrix\Mail\Internal\Service\SourceGeneration\ManualMigrationControl;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationOperationSchema;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationService;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationStage;
use Bitrix\Mail\Internal\Service\SourceGeneration\OperationTracker;
use Bitrix\Mail\Internals\MigrationOperationTable;
use Bitrix\Main\Type\DateTime;

final class MigrationStatusProvider
{
	/** Everything {@see self::fromRow()} reads; the row itself never leaves this class. */
	private const STATUS_FIELDS = [
		'MAILBOX_ID', 'OPERATION_ID', 'EXEC_STATE', 'STAGE', 'SWITCH_AUTHORIZED',
		'AUTO_RETRY', 'NEXT_RUN', 'LAST_ERROR_CODE', 'ERROR_OWNER',
	];

	public function __construct(
		private readonly OperationTracker $tracker = new OperationTracker(),
		private readonly MigrationOperationSchema $schema = new MigrationOperationSchema(),
		private readonly ManualMigrationControl $manualControl = new ManualMigrationControl(),
	)
	{
	}

	/** Visibility and identity only: the manual wait costs a row of its own, see getForDisplay(). */
	public function get(int $mailboxId, string $operationId): ?MigrationStatus
	{
		if (!$this->schema->isReady())
		{
			return null;
		}

		return $this->fromRow($this->tracker->find($mailboxId, $operationId));
	}

	/** Visibility and identity only: the manual wait costs a row of its own, see getLatestForDisplay(). */
	public function getLatest(int $mailboxId): ?MigrationStatus
	{
		if (!$this->schema->isReady())
		{
			return null;
		}

		return $this->fromRow($this->tracker->findLatestForMailbox($mailboxId));
	}

	/** Call where the status text itself is shown or published: it also reads the manual wait. */
	public function getForDisplay(int $mailboxId, string $operationId): ?MigrationStatus
	{
		if (!$this->schema->isReady())
		{
			return null;
		}

		$operation = $this->tracker->find($mailboxId, $operationId);

		return $this->fromRow($operation, $this->isManualWaiting($mailboxId, $operation));
	}

	/** Call where the status text itself is shown or published: it also reads the manual wait. */
	public function getLatestForDisplay(int $mailboxId): ?MigrationStatus
	{
		if (!$this->schema->isReady())
		{
			return null;
		}

		$operation = $this->tracker->findLatestForMailbox($mailboxId);

		return $this->fromRow($operation, $this->isManualWaiting($mailboxId, $operation));
	}

	/** @return array<int, MigrationStatus> Statuses indexed by mailbox id. */
	public function getLatestForMailboxes(array $mailboxIds): array
	{
		if (!$this->schema->isReady())
		{
			return [];
		}

		$rows = $this->tracker->findLatestForMailboxes($mailboxIds, self::STATUS_FIELDS);
		$manualWaiting = $this->manualControl->findWaitingMailboxes($rows);

		$statuses = [];
		foreach ($rows as $mailboxId => $row)
		{
			$status = $this->fromRow($row, isset($manualWaiting[(int)$mailboxId]));
			if ($status !== null)
			{
				$statuses[(int)$mailboxId] = $status;
			}
		}

		return $statuses;
	}

	public function fromRow(?array $operation, bool $manualWaiting = false): ?MigrationStatus
	{
		if ($operation === null)
		{
			return null;
		}

		$knownStage = MigrationStage::tryFrom((string)$operation['STAGE']);
		$stage = $knownStage ?? MigrationStage::Legacy;
		$state = (string)$operation['EXEC_STATE'];
		$status = $this->statusOf(
			$state,
			$stage,
			$manualWaiting && ManualMigrationControl::waitingAppliesTo($knownStage, $state),
		);
		$authorized = (string)$operation['SWITCH_AUTHORIZED'] === 'Y';
		$visibility = !$authorized
			? 'hidden'
			: (in_array($state, [
				MigrationOperationTable::STATE_DONE,
				MigrationOperationTable::STATE_CANCELLED,
				MigrationOperationTable::STATE_NEEDS_ATTENTION,
			], true)
				? 'terminal'
				: 'active');

		$nextRun = $operation['NEXT_RUN'] ?? null;

		return new MigrationStatus(
			status: $status,
			reason: $this->reasonOf($operation, $stage),
			errorCode: $this->nullableString($operation['LAST_ERROR_CODE'] ?? null),
			errorOwner: $this->nullableString($operation['ERROR_OWNER'] ?? null),
			retryable: (string)$operation['AUTO_RETRY'] === 'Y',
			nextAttemptAt: $nextRun instanceof DateTime ? $nextRun->getTimestamp() : null,
			operationId: (string)$operation['OPERATION_ID'],
			mailboxId: (int)$operation['MAILBOX_ID'],
			visibility: $visibility,
			publicStatus: match (true)
			{
				$visibility === 'hidden' => null,
				$state === MigrationOperationTable::STATE_NEEDS_ATTENTION => 'cancelled',
				default => $status,
			},
		);
	}

	private function isManualWaiting(int $mailboxId, ?array $operation): bool
	{
		if ($operation === null)
		{
			return false;
		}

		return isset($this->manualControl->findWaitingMailboxes([$mailboxId => $operation])[$mailboxId]);
	}

	private function statusOf(string $state, MigrationStage $stage, bool $manualWaiting): string
	{
		if ($manualWaiting)
		{
			return MigrationStatus::STATUS_MANUAL_WAITING;
		}

		return match ($state)
		{
			MigrationOperationTable::STATE_WAITING => 'waiting',
			MigrationOperationTable::STATE_NEEDS_ATTENTION => 'blocked',
			MigrationOperationTable::STATE_CANCELLING => 'cancelling',
			MigrationOperationTable::STATE_DONE => 'done',
			MigrationOperationTable::STATE_CANCELLED => 'cancelled',
			default => $stage === MigrationStage::ReadyToSwitch ? 'switching' : 'running',
		};
	}

	private function reasonOf(array $operation, MigrationStage $stage): ?string
	{
		$error = (string)($operation['LAST_ERROR_CODE'] ?? '');
		if (in_array($error, [
			MigrationService::ERROR_MIGRATION_STOPPED,
			MigrationService::ERROR_FEATURE_DISABLED,
		], true))
		{
			return 'migration_paused';
		}

		if ((string)$operation['EXEC_STATE'] === MigrationOperationTable::STATE_NEEDS_ATTENTION)
		{
			return 'action_required';
		}

		if ($stage === MigrationStage::AwaitingSwitchAuthorization)
		{
			return 'waiting_for_switch_authorization';
		}

		return null;
	}

	private function nullableString(mixed $value): ?string
	{
		$value = trim((string)$value);

		return $value === '' ? null : $value;
	}
}
