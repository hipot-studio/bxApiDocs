<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\MailService;

use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationOperationSchema;
use Bitrix\Mail\Internal\Service\SourceGeneration\ManualMigrationControl;
use Bitrix\Mail\Internal\Service\SourceGeneration\ManualMigrationControlSchema;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationService;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationStage;
use Bitrix\Mail\Internals\MailboxSourceGenerationTable;
use Bitrix\Mail\Internals\MigrationOperationTable;
use Bitrix\Mail\Internals\MigrationManualControlTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Type\DateTime;

final class AdminMigrationProgressProvider
{
	private const MAX_SAFE_INTEGER = 9007199254740991;
	private const MISSING_FOLDER_ROLES = ['inbox', 'sent', 'drafts', 'trash', 'spam'];

	public function __construct(
		private readonly MigrationOperationSchema $schema = new MigrationOperationSchema(),
		private readonly MigrationStatusProvider $statusProvider = new MigrationStatusProvider(),
		private readonly AdminMigrationProgressAccess $access = new AdminMigrationProgressAccess(),
		private readonly ManualMigrationControlSchema $manualSchema = new ManualMigrationControlSchema(),
	)
	{
	}

	public function getLatest(int $mailboxId): ?array
	{
		return $this->readLatest($mailboxId, false);
	}

	public function getLatestForDisplay(int $mailboxId): ?array
	{
		return $this->readLatest($mailboxId, true);
	}

	private function readLatest(int $mailboxId, bool $applyDisplayVisibility): ?array
	{
		try
		{
			$ready = $this->schema->isReady();
		}
		catch (\UnexpectedValueException)
		{
			$ready = false;
		}

		$mailbox = MailboxTable::query()
			->setSelect($ready ? ['ID', 'ACTIVE_GENERATION_ID'] : ['ID'])
			->where('ID', $mailboxId)
			->fetch()
		;
		if (!$mailbox)
		{
			return null;
		}
		if (!$ready)
		{
			return $this->emptyProgress($mailboxId, 'unavailable');
		}

		$operation = MigrationOperationTable::query()
			->setSelect([
				'MAILBOX_ID', 'OPERATION_ID', 'GENERATION_ID', 'EXEC_STATE', 'STAGE',
				'SNAPSHOT_ACCEPTED', 'SWITCH_AUTHORIZED', 'AUTO_RETRY', 'NEXT_RUN',
				'LAST_PROGRESS', 'LAST_ERROR_CODE', 'DATE_CREATE', 'DATE_MODIFY',
			])
			->where('MAILBOX_ID', $mailboxId)
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
			->fetch()
		;
		if (
			$applyDisplayVisibility
			&& $operation
			&& !$this->access->canShowProgress(
				in_array($this->statusProvider->fromRow($operation)?->visibility, ['active', 'terminal'], true),
			)
		)
		{
			return $this->emptyProgress($mailboxId, 'unavailable');
		}

		$generation = null;
		if ($operation && (int)$operation['GENERATION_ID'] > 0)
		{
			$generation = MailboxSourceGenerationTable::query()
				->setSelect(['ID', 'MAILBOX_ID', 'OPERATION_ID', 'STATUS', 'OPTIONS'])
				->where('ID', (int)$operation['GENERATION_ID'])
				->fetch()
			;
		}

		$manualAvailable = false;
		$manualControl = null;
		if ($operation && ManualMigrationControl::isEnabled())
		{
			try
			{
				$manualAvailable = $this->manualSchema->isReady();
			}
			catch (\UnexpectedValueException)
			{
				$manualAvailable = false;
			}
			if ($manualAvailable)
			{
				$manualControl = MigrationManualControlTable::query()
					->setSelect(['ENABLED', 'CHECKPOINT', 'VERSION', 'GRANTED', 'WAIT_SINCE'])
					->where('MAILBOX_ID', $mailboxId)
					->where('OPERATION_ID', (string)$operation['OPERATION_ID'])
					->setLimit(1)
					->fetch()
				;
			}
		}

		return $this->fromRows(
			$mailbox,
			$operation ?: null,
			$generation ?: null,
			$manualControl ?: null,
			$manualAvailable,
		);
	}

	public function fromRows(
		array $mailbox,
		?array $operation,
		?array $generation,
		?array $manualControl = null,
		bool $manualAvailable = false,
	): array
	{
		$mailboxId = (int)$mailbox['ID'];
		$progress = $this->emptyProgress($mailboxId, $operation === null ? 'no_operation' : 'available');
		if ($operation === null)
		{
			return $progress;
		}

		$progress['operationId'] = (string)$operation['OPERATION_ID'];
		$state = (string)$operation['EXEC_STATE'];
		$stage = MigrationStage::tryFrom((string)$operation['STAGE']);
		$generationId = (int)($operation['GENERATION_ID'] ?? 0);
		$cancelled = $state === MigrationOperationTable::STATE_CANCELLED;
		$hasGeneration = $generationId > 0 && $generation !== null;
		$inconsistent = (int)$operation['MAILBOX_ID'] !== $mailboxId
			|| ($generationId > 0 && $generation === null && !$cancelled)
			|| ($hasGeneration && (
				(int)$generation['ID'] !== $generationId
				|| (int)$generation['MAILBOX_ID'] !== $mailboxId
				|| (string)$generation['OPERATION_ID'] !== $progress['operationId']
			));
		if ($state === MigrationOperationTable::STATE_DONE)
		{
			$inconsistent = $inconsistent
				|| $stage !== MigrationStage::Switched
				|| !$hasGeneration
				|| ($generation['STATUS'] ?? null) !== MailboxSourceGenerationTable::STATUS_ACTIVE
				|| (int)($mailbox['ACTIVE_GENERATION_ID'] ?? 0) !== $generationId;
		}
		elseif ($stage === MigrationStage::Switched)
		{
			$inconsistent = true;
		}
		if ($inconsistent)
		{
			$progress['availability'] = 'unavailable';
			$progress['reason'] = 'inconsistent_state';

			return $progress;
		}

		$progress['status'] = $this->statusOf($state, $stage);
		$progress['stage'] = $stage?->value;
		$options = $hasGeneration && is_array($generation['OPTIONS'] ?? null) ? $generation['OPTIONS'] : [];
		$progress['reason'] = $this->reasonOf($operation, $stage);
		if ($progress['reason'] === 'action_required')
		{
			$progress['missingFolderRole'] = $this->missingFolderRole($operation, $options);
			if ($progress['missingFolderRole'] !== null)
			{
				$progress['reason'] = 'target_folder_missing';
			}
		}
		$progress['snapshotAccepted'] = ($operation['SNAPSHOT_ACCEPTED'] ?? null) === 'Y';
		$progress['switchAuthorized'] = ($operation['SWITCH_AUTHORIZED'] ?? null) === 'Y';
		$progress['startedAt'] = $this->timestamp($operation['DATE_CREATE'] ?? null);
		$progress['lastProgressAt'] = $this->timestamp($operation['LAST_PROGRESS'] ?? null);
		$progress['updatedAt'] = $this->timestamp($operation['DATE_MODIFY'] ?? null);
		if ($state === MigrationOperationTable::STATE_WAITING && ($operation['AUTO_RETRY'] ?? null) === 'Y')
		{
			$progress['nextAttemptAt'] = $this->timestamp($operation['NEXT_RUN'] ?? null);
		}
		$progress['manualControl'] = $this->manualControl($operation, $stage, $manualControl, $manualAvailable);

		$metrics = is_array($options['metrics'] ?? null) ? $options['metrics'] : [];
		$import = is_array($metrics['import'] ?? null) ? $metrics['import'] : [];
		$tail = is_array($metrics['tail'] ?? null) ? $metrics['tail'] : [];
		$progress['metrics']['importProcessed'] = $this->counter($import['processed'] ?? null);
		$progress['metrics']['tailAppended'] = $this->counter($tail['appended'] ?? null);
		foreach (['left_behind', 'set_aside', 'envelope_only'] as $key)
		{
			$count = $this->counter($tail[$key] ?? null);
			if ($count !== null && $count > 0)
			{
				$progress['warnings'][] = [
					'code' => 'tail_' . $key,
					'count' => $count,
				];
			}
		}

		return $progress;
	}

	public function getListStatus(array $operation, bool $manualWaiting = false): ?string
	{
		$state = (string)$operation['EXEC_STATE'];
		if (in_array($state, [MigrationOperationTable::STATE_DONE, MigrationOperationTable::STATE_CANCELLED], true))
		{
			return null;
		}

		$stage = MigrationStage::tryFrom((string)$operation['STAGE']);
		if ($manualWaiting && ManualMigrationControl::waitingAppliesTo($stage, $state))
		{
			return MigrationStatus::STATUS_MANUAL_WAITING;
		}

		return $this->statusOf($state, $stage);
	}

	private function statusOf(string $state, ?MigrationStage $stage): string
	{
		if ($state === MigrationOperationTable::STATE_CANCELLED)
		{
			return 'cancelled';
		}
		if ($stage === null)
		{
			return 'unknown';
		}

		return match ($state)
		{
			MigrationOperationTable::STATE_WAITING => 'waiting',
			MigrationOperationTable::STATE_NEEDS_ATTENTION => 'blocked',
			MigrationOperationTable::STATE_CANCELLING => 'cancelling',
			MigrationOperationTable::STATE_DONE => 'done',
			MigrationOperationTable::STATE_RUNNING,
			MigrationOperationTable::STATE_SCHEDULED => $stage === MigrationStage::ReadyToSwitch ? 'switching' : 'running',
			default => 'unknown',
		};
	}

	private function reasonOf(array $operation, ?MigrationStage $stage): ?string
	{
		if ($stage === null)
		{
			return 'unknown_stage';
		}
		if (in_array($operation['LAST_ERROR_CODE'] ?? null, [
			MigrationService::ERROR_MIGRATION_STOPPED,
			MigrationService::ERROR_FEATURE_DISABLED,
		], true))
		{
			return 'migration_paused';
		}
		if ($operation['EXEC_STATE'] === MigrationOperationTable::STATE_NEEDS_ATTENTION)
		{
			return 'action_required';
		}

		return $stage === MigrationStage::AwaitingSwitchAuthorization ? 'waiting_for_switch_authorization' : null;
	}

	private function missingFolderRole(array $operation, array $options): ?string
	{
		if (!in_array($operation['LAST_ERROR_CODE'] ?? null, [
			MigrationService::ERROR_INCOME_FOLDER_MISSING,
			MigrationService::ERROR_DIRECTORY_ROLES_AMBIGUOUS,
		], true))
		{
			return null;
		}

		$role = $options[MigrationService::STATE_MISSING_TARGET_ROLE] ?? null;

		return in_array($role, self::MISSING_FOLDER_ROLES, true) ? $role : null;
	}

	private function counter(mixed $value): ?int
	{
		if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1)
		{
			if (strlen($value) > 16 || (strlen($value) === 16 && strcmp($value, (string)self::MAX_SAFE_INTEGER) > 0))
			{
				return null;
			}

			$value = (int)$value;
		}

		return is_int($value) && $value >= 0 && $value <= self::MAX_SAFE_INTEGER ? $value : null;
	}

	private function timestamp(mixed $value): ?int
	{
		return $value instanceof DateTime ? $value->getTimestamp() : null;
	}

	private function manualControl(
		array $operation,
		?MigrationStage $stage,
		?array $control,
		bool $available,
	): ?array
	{
		if (
			!$available
			|| $stage === null
			|| $stage->isFinal()
			|| in_array((string)$operation['EXEC_STATE'], [
				MigrationOperationTable::STATE_DONE,
				MigrationOperationTable::STATE_CANCELLED,
				MigrationOperationTable::STATE_CANCELLING,
				MigrationOperationTable::STATE_NEEDS_ATTENTION,
			], true)
		)
		{
			return null;
		}

		$enabled = (string)($control['ENABLED'] ?? 'N') === 'Y';
		$granted = $enabled && (string)($control['GRANTED'] ?? 'N') === 'Y';
		$checkpoint = $enabled
			? (string)($control['CHECKPOINT'] ?? '')
			: ManualMigrationControl::checkpointForStage($stage);
		$allowedCommands = [];
		if (!$enabled && $stage !== MigrationStage::ReadyToSwitch)
		{
			$allowedCommands[] = ManualMigrationControl::COMMAND_ENABLE;
		}
		elseif ($enabled)
		{
			if (
				!$granted
				&& (string)$operation['EXEC_STATE'] === MigrationOperationTable::STATE_WAITING
			)
			{
				$allowedCommands[] = ManualMigrationControl::COMMAND_GRANT;
			}
			$allowedCommands[] = ManualMigrationControl::COMMAND_AUTOMATIC;
		}

		return [
			'enabled' => $enabled,
			'waiting' => $enabled
				&& !$granted
				&& (string)$operation['EXEC_STATE'] === MigrationOperationTable::STATE_WAITING
				&& ($control['WAIT_SINCE'] ?? null) instanceof DateTime,
			'checkpoint' => $checkpoint !== '' ? $checkpoint : null,
			'version' => (int)($control['VERSION'] ?? 0),
			'granted' => $granted,
			'waitingSince' => $enabled ? $this->timestamp($control['WAIT_SINCE'] ?? null) : null,
			'allowedCommands' => $allowedCommands,
		];
	}

	private function emptyProgress(int $mailboxId, string $availability): array
	{
		return [
			'mailboxId' => $mailboxId,
			'operationId' => null,
			'availability' => $availability,
			'status' => null,
			'stage' => null,
			'reason' => null,
			'missingFolderRole' => null,
			'snapshotAccepted' => null,
			'switchAuthorized' => null,
			'startedAt' => null,
			'lastProgressAt' => null,
			'updatedAt' => null,
			'sampledAt' => time(),
			'nextAttemptAt' => null,
			'metrics' => [
				'importProcessed' => null,
				'tailAppended' => null,
			],
			'warnings' => [],
			'manualControl' => null,
		];
	}
}
