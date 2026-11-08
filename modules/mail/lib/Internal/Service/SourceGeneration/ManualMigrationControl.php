<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Internal\Service\Mailbox\SettingsWriteCoordinator;
use Bitrix\Mail\Internals\MigrationManualControlTable;
use Bitrix\Mail\Internals\MigrationOperationTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

final class ManualMigrationControl
{
	public const OPTION_ENABLED = 'migration_manual_stage_control_enabled';

	/**
	 * Raised the first time a control row is stored and never lowered on its own: a row outlives
	 * the flag, and the guard has to lift a mode that was turned off portal wide. It tells a portal
	 * that only took the update from one that used the mode, and the first opens no control table.
	 */
	public const OPTION_TOUCHED = 'migration_manual_stage_control_touched';

	public const COMMAND_ENABLE = 'enable';
	public const COMMAND_GRANT = 'grant';
	public const COMMAND_AUTOMATIC = 'automatic';

	public const CHECKPOINT_PREPARATION = 'preparation';
	public const CHECKPOINT_CHECKING = 'checking';
	public const CHECKPOINT_LOADING = 'loading';
	public const CHECKPOINT_TAIL = 'tail';
	public const CHECKPOINT_COMPLETION = 'completion';

	public const ERROR_DISABLED = 'MAIL_MIGRATION_MANUAL_DISABLED';
	public const ERROR_UNAVAILABLE = 'MAIL_MIGRATION_MANUAL_UNAVAILABLE';
	public const ERROR_BUSY = 'MAIL_MIGRATION_MANUAL_BUSY';
	public const ERROR_CONFLICT = 'MAIL_MIGRATION_MANUAL_CONFLICT';
	public const ERROR_TOO_LATE = 'MAIL_MIGRATION_MANUAL_TOO_LATE';

	private const CHECKPOINTS = [
		self::CHECKPOINT_PREPARATION,
		self::CHECKPOINT_CHECKING,
		self::CHECKPOINT_LOADING,
		self::CHECKPOINT_TAIL,
		self::CHECKPOINT_COMPLETION,
	];

	public function __construct(
		private readonly ManualMigrationControlSchema $schema = new ManualMigrationControlSchema(),
		private readonly SettingsWriteCoordinator $coordinator = new SettingsWriteCoordinator(),
		private ?MigrationUserStatusPublisher $publisher = null,
		private ?OperationTracker $tracker = null,
	)
	{
	}

	public static function isEnabled(): bool
	{
		return Option::get('mail', self::OPTION_ENABLED, 'N') === 'Y';
	}

	public static function checkpointForStage(MigrationStage $stage): ?string
	{
		return match ($stage)
		{
			MigrationStage::Legacy,
			MigrationStage::InitializingG1,
			MigrationStage::G1Ready,
			MigrationStage::PreparingG2 => self::CHECKPOINT_PREPARATION,
			MigrationStage::ShadowMatching => self::CHECKPOINT_CHECKING,
			MigrationStage::ResettingImport,
			MigrationStage::Matching => self::CHECKPOINT_LOADING,
			MigrationStage::TailAppend => self::CHECKPOINT_TAIL,
			MigrationStage::AwaitingSwitchAuthorization,
			MigrationStage::ReadyToSwitch => self::CHECKPOINT_COMPLETION,
			MigrationStage::Switched => null,
		};
	}

	/**
	 * An unknown stage says nothing about the stop. On the completion checkpoint the migration
	 * waits for the grant and for the switch authorization of mailservice at once, and only the
	 * administrator can act, so a stop without a grant is a manual wait: naming anything else
	 * there hides the one action the reader of the row can take. The grant lowers the flag on
	 * its own and leaves the wait for the service alone.
	 */
	public static function waitingAppliesTo(?MigrationStage $stage, string $execState): bool
	{
		return $stage !== null && $execState === MigrationOperationTable::STATE_WAITING;
	}

	/** Joins the transaction that has just created the operation under OperationLock. */
	public function armNewOperationUnderTransaction(
		int $mailboxId,
		string $operationId,
		MigrationStage $stage,
	): bool
	{
		if (!self::isEnabled())
		{
			return true;
		}
		if (!$this->isSchemaReady())
		{
			return false;
		}

		$checkpoint = self::checkpointForStage($stage);
		if ($checkpoint === null)
		{
			return true;
		}
		if ($this->findWithinTransaction($mailboxId, $operationId) !== null)
		{
			return false;
		}

		return $this->createEnabledControl($mailboxId, $operationId, $checkpoint) !== null;
	}

	public function execute(
		int $mailboxId,
		string $operationId,
		string $command,
		int $expectedVersion,
		?string $expectedCheckpoint,
	): Result
	{
		if (!self::isEnabled())
		{
			return $this->error(self::ERROR_DISABLED, 'Manual migration control is disabled');
		}
		if (!$this->isSchemaReady())
		{
			return $this->error(self::ERROR_UNAVAILABLE, 'Manual migration control is unavailable');
		}
		if (!in_array($command, [self::COMMAND_ENABLE, self::COMMAND_GRANT, self::COMMAND_AUTOMATIC], true))
		{
			return $this->error(self::ERROR_UNAVAILABLE, 'The manual migration command is invalid');
		}
		if (!OperationLock::acquire($mailboxId))
		{
			return $this->error(self::ERROR_BUSY, 'The migration operation is busy');
		}

		try
		{
			$result = $this->coordinator->execute(
				$mailboxId,
				function (array $state) use (
					$mailboxId,
					$operationId,
					$command,
					$expectedVersion,
					$expectedCheckpoint,
				): Result {
					$result = $this->executeUnderTransaction(
						$state,
						$mailboxId,
						$operationId,
						$command,
						$expectedVersion,
						$expectedCheckpoint,
					);
					if ($result->isSuccess())
					{
						/*
							The command changes the public status behind the back of the agent,
							which compares its own passes only. Without this the chip of an open
							list keeps promising a wait that the command has just ended.
						*/
						$this->markPublicationPending($state, $operationId);
					}

					return $result;
				},
				withMigrationState: true,
			);
		}
		finally
		{
			OperationLock::release($mailboxId);
		}

		/*
			Delivered outside the lock, because the delivery takes it again, and only the delivery
			lowers the flag of the row. A message lost here - a dead process, a broken pull - leaves
			the flag up for the agent, instead of a list frozen on a wait that has ended.
		*/
		if ($result->isSuccess())
		{
			try
			{
				$this->tracker()->publishPending($mailboxId, $operationId, $this->publisher());
			}
			catch (\Throwable $exception)
			{
				Application::getInstance()->getExceptionHandler()->writeToLog($exception);
			}
		}

		return $result;
	}

	public function guardUnderLock(int $mailboxId, string $operationId, string $checkpoint): Result
	{
		if (!in_array($checkpoint, self::CHECKPOINTS, true))
		{
			return $this->error(self::ERROR_UNAVAILABLE, 'The manual migration checkpoint is invalid');
		}

		if (!$this->mayHoldState())
		{
			return $this->automaticState(null);
		}

		$enabled = self::isEnabled();
		$schemaReady = $this->isSchemaReady();
		if (!$enabled && !$schemaReady)
		{
			return (new Result())->setData([
				'manualControlled' => false,
				'manualWaiting' => false,
				'manualVersion' => null,
			]);
		}
		if (!$schemaReady)
		{
			return $this->error(self::ERROR_UNAVAILABLE, 'Manual migration control is unavailable');
		}
		$control = $this->findCurrent($mailboxId, $operationId);
		if ($control === null || (string)$control['ENABLED'] !== 'Y')
		{
			return $this->automaticState($control);
		}

		return $this->coordinator->execute(
			$mailboxId,
			function (array $state) use ($enabled, $mailboxId, $operationId, $checkpoint): Result {
				$control = $this->findWithinTransaction($mailboxId, $operationId);
				if ($control === null || (string)$control['ENABLED'] !== 'Y')
				{
					return $this->automaticState($control);
				}
				$operation = $this->findAddressedOperation($state, $operationId);
				if ($operation === null)
				{
					return $this->error(self::ERROR_UNAVAILABLE, 'The migration operation is unavailable');
				}

				if (!$enabled)
				{
					$control = $this->setAutomatic($control, $operation, false);

					return (new Result())->setData([
						'manualControlled' => false,
						'manualWaiting' => false,
						'manualVersion' => (int)$control['VERSION'],
						'manualRetryResumedAt' => $control['RETRY_RESUMED_AT'] instanceof DateTime
							? $control['RETRY_RESUMED_AT']
							: null,
						'manualRetryAttemptsReset' => true,
					]);
				}

				if ((string)$control['CHECKPOINT'] !== $checkpoint)
				{
					$control = $this->moveToCheckpoint($control, $checkpoint, true);
				}
				elseif ((string)$control['GRANTED'] !== 'Y' && !($control['WAIT_SINCE'] instanceof DateTime))
				{
					$control = $this->markWaiting($control);
				}

				return (new Result())->setData([
					'manualControlled' => true,
					'manualWaiting' => (string)$control['GRANTED'] !== 'Y',
					'manualVersion' => (int)$control['VERSION'],
					'manualRetryResumedAt' => $control['RETRY_RESUMED_AT'] instanceof DateTime
						? $control['RETRY_RESUMED_AT']
						: null,
				]);
			},
			withMigrationState: true,
		);
	}

	public function invalidateForResetUnderLock(
		int $mailboxId,
		string $operationId,
		?callable $startReset = null,
	): Result
	{
		$enabled = self::isEnabled();
		if (!$this->mayHoldState() || (!$enabled && !$this->isSchemaReady()))
		{
			if ($startReset !== null)
			{
				$startReset();
			}

			return (new Result())->setData(['manualVersion' => null]);
		}
		if (!$this->isSchemaReady())
		{
			return $this->error(self::ERROR_UNAVAILABLE, 'Manual migration control is unavailable');
		}

		return $this->coordinator->execute(
			$mailboxId,
			function () use ($enabled, $mailboxId, $operationId, $startReset): Result {
				if ($startReset !== null)
				{
					$startReset();
				}
				$control = $this->findWithinTransaction($mailboxId, $operationId);
				if ($control === null)
				{
					return (new Result())->setData(['manualVersion' => 0]);
				}
				if (!$enabled && (string)$control['ENABLED'] === 'Y')
				{
					$control = $this->updateControl($control, [
						'ENABLED' => 'N',
						'GRANTED' => 'N',
						'WAIT_SINCE' => null,
					]);
				}
				elseif (
					$enabled
					&& (string)$control['ENABLED'] === 'Y'
					&& ((string)$control['GRANTED'] === 'Y' || $control['WAIT_SINCE'] instanceof DateTime)
				)
				{
					$control = $this->moveToCheckpoint($control, self::CHECKPOINT_LOADING, false);
				}

				return (new Result())->setData(['manualVersion' => (int)$control['VERSION']]);
			},
			withMigrationState: true,
		);
	}

	public function disableUnderLock(int $mailboxId, string $operationId): Result
	{
		if (!$this->mayHoldState())
		{
			return (new Result())->setData(['manualVersion' => null]);
		}
		if (!$this->schemaExists())
		{
			return (new Result())->setData(['manualVersion' => null]);
		}
		if (!$this->isSchemaReady())
		{
			return $this->error(self::ERROR_UNAVAILABLE, 'Manual migration control is unavailable');
		}

		return $this->coordinator->execute(
			$mailboxId,
			function () use ($mailboxId, $operationId): Result {
				$control = $this->findWithinTransaction($mailboxId, $operationId);
				if ($control === null || (string)$control['ENABLED'] !== 'Y')
				{
					return (new Result())->setData([
						'manualVersion' => $control === null ? 0 : (int)$control['VERSION'],
					]);
				}

				$control = $this->updateControl($control, [
					'ENABLED' => 'N',
					'GRANTED' => 'N',
					'WAIT_SINCE' => null,
				]);

				return (new Result())->setData(['manualVersion' => (int)$control['VERSION']]);
			},
		);
	}

	public function find(int $mailboxId, string $operationId): ?array
	{
		if (!$this->isSchemaReady())
		{
			return null;
		}

		return $this->findCurrent($mailboxId, $operationId);
	}

	/**
	 * The row must match the waiting state of the progress panel word for word, and an operation
	 * stopped by an error waits for the stop to be lifted, not for a grant.
	 *
	 * @param array<int, array<string, mixed>> $operations Operation rows indexed by mailbox id.
	 * @return array<int, true> Mailbox ids whose operation stands stopped without a manual grant.
	 */
	public function findWaitingMailboxes(array $operations): array
	{
		$pending = [];
		foreach ($operations as $mailboxId => $operation)
		{
			if (
				(string)($operation['EXEC_STATE'] ?? '') === MigrationOperationTable::STATE_WAITING
				&& (string)($operation['LAST_ERROR_CODE'] ?? '') === ''
			)
			{
				$pending[(int)$mailboxId] = (string)($operation['OPERATION_ID'] ?? '');
			}
		}
		if ($pending === [] || !self::isEnabled() || !$this->isSchemaReady())
		{
			return [];
		}

		$rows = MigrationManualControlTable::query()
			->setSelect(['MAILBOX_ID', 'OPERATION_ID', 'ENABLED', 'GRANTED', 'WAIT_SINCE'])
			->whereIn('MAILBOX_ID', array_keys($pending))
			->whereIn('OPERATION_ID', array_values(array_unique($pending)))
			->fetchAll()
		;

		$waiting = [];
		foreach ($rows as $row)
		{
			$mailboxId = (int)$row['MAILBOX_ID'];
			if (
				($pending[$mailboxId] ?? null) === (string)$row['OPERATION_ID']
				&& (string)$row['ENABLED'] === 'Y'
				&& (string)$row['GRANTED'] !== 'Y'
				&& $row['WAIT_SINCE'] instanceof DateTime
			)
			{
				$waiting[$mailboxId] = true;
			}
		}

		return $waiting;
	}

	/**
	 * A table that holds nothing needs no snapshot to compare the outcome of the pass against.
	 * This is the read the agent used to pay for every operation of every batch on a portal that
	 * merely took the update.
	 */
	public function readVersion(int $mailboxId, string $operationId): ?int
	{
		if (!$this->mayHoldState())
		{
			return 0;
		}
		if (!$this->isSchemaReady())
		{
			return null;
		}

		$control = $this->findCurrent($mailboxId, $operationId, ['VERSION']);

		return $control ? (int)$control['VERSION'] : 0;
	}

	public function matchesVersionUnderTransaction(
		int $mailboxId,
		string $operationId,
		?int $expectedVersion,
	): bool
	{
		/*
			The snapshot of the pass was taken without a read for the same reason it is compared
			without one: the table holds nothing at all, so there is nothing for a command to
			have changed. A flag raised mid pass drops this shortcut and the comparison below
			protects the command again.
		*/
		if (!$this->mayHoldState())
		{
			return $expectedVersion === null || $expectedVersion === 0;
		}
		if ($expectedVersion === null)
		{
			if (!$this->isSchemaReady())
			{
				return true;
			}

			return $this->findCurrent($mailboxId, $operationId) === null;
		}
		if (!$this->isSchemaReady())
		{
			return false;
		}

		$control = $this->findCurrent($mailboxId, $operationId);

		return $control === null
			? $expectedVersion === 0
			: (int)$control['VERSION'] === $expectedVersion;
	}

	public function invalidateForCancellationUnderLock(int $mailboxId, string $operationId): void
	{
		if (!$this->mayHoldState())
		{
			return;
		}
		if (!$this->schemaExists())
		{
			return;
		}

		$control = $this->findWithinTransaction($mailboxId, $operationId);
		if ($control === null)
		{
			return;
		}

		$this->updateControl($control, [
			'ENABLED' => 'N',
			'GRANTED' => 'N',
			'WAIT_SINCE' => null,
		]);
	}

	public function consumeRetryResumeUnderTransaction(
		int $mailboxId,
		string $operationId,
		?int $expectedVersion,
	): void
	{
		if ($expectedVersion === null || !$this->mayHoldState() || !$this->isSchemaReady())
		{
			return;
		}

		$control = $this->findWithinTransaction($mailboxId, $operationId);
		if (
			$control === null
			|| (int)$control['VERSION'] !== $expectedVersion
			|| !($control['RETRY_RESUMED_AT'] instanceof DateTime)
		)
		{
			return;
		}

		$this->updateControl($control, ['RETRY_RESUMED_AT' => null], false);
	}

	private function executeUnderTransaction(
		array $state,
		int $mailboxId,
		string $operationId,
		string $command,
		int $expectedVersion,
		?string $expectedCheckpoint,
	): Result
	{
		$operation = $this->findAddressedOperation($state, $operationId);
		if ($operation === null || !$this->isExecutable($operation))
		{
			return $this->error(self::ERROR_UNAVAILABLE, 'The migration operation is unavailable');
		}

		$stage = MigrationStage::tryFrom((string)$operation['STAGE']) ?? MigrationStage::Legacy;
		$checkpoint = self::checkpointForStage($stage);
		if ($checkpoint === null)
		{
			return $this->error(self::ERROR_TOO_LATE, 'The migration operation is complete');
		}

		$control = $this->findWithinTransaction($mailboxId, $operationId);
		$currentVersion = $control === null ? 0 : (int)$control['VERSION'];
		if ($command === self::COMMAND_GRANT && $control !== null)
		{
			if (
				$expectedVersion === $currentVersion - 1
				&& (string)$control['CHECKPOINT'] === $expectedCheckpoint
				&& (string)$control['GRANTED'] === 'Y'
			)
			{
				return (new Result())->setData(['control' => $control]);
			}
		}
		if ($expectedVersion !== $currentVersion)
		{
			return $this->error(self::ERROR_CONFLICT, 'The manual migration state has changed');
		}

		return match ($command)
		{
			self::COMMAND_ENABLE => $this->enable($mailboxId, $operationId, $operation, $control, $checkpoint),
			self::COMMAND_GRANT => $this->grant($operation, $control, $expectedCheckpoint),
			self::COMMAND_AUTOMATIC => $this->automatic($operation, $control),
		};
	}

	private function enable(
		int $mailboxId,
		string $operationId,
		array $operation,
		?array $control,
		string $checkpoint,
	): Result
	{
		$stage = MigrationStage::tryFrom((string)$operation['STAGE']) ?? MigrationStage::Legacy;
		if ($stage === MigrationStage::ReadyToSwitch)
		{
			return $this->error(self::ERROR_TOO_LATE, 'The final migration work may have started');
		}
		if ($control !== null && (string)$control['ENABLED'] === 'Y')
		{
			return $this->error(self::ERROR_CONFLICT, 'The migration operation is already controlled manually');
		}

		if ($control === null)
		{
			$control = $this->createEnabledControl($mailboxId, $operationId, $checkpoint);
		}
		elseif ((string)$control['ENABLED'] !== 'Y')
		{
			$this->rememberStoredState();
			$now = new DateTime();
			$control = $this->updateControl($control, [
				'ENABLED' => 'Y',
				'CHECKPOINT' => $checkpoint,
				'GRANTED' => 'N',
				'WAIT_SINCE' => $now,
			]);
		}

		if ($control === null)
		{
			return $this->error(self::ERROR_UNAVAILABLE, 'Manual migration control was not saved');
		}
		$this->scheduleWaitingOperation($operation);

		return (new Result())->setData(['control' => $control]);
	}

	private function createEnabledControl(int $mailboxId, string $operationId, string $checkpoint): ?array
	{
		$now = new DateTime();
		$this->rememberStoredState();
		$added = MigrationManualControlTable::add([
			'MAILBOX_ID' => $mailboxId,
			'OPERATION_ID' => $operationId,
			'ENABLED' => 'Y',
			'CHECKPOINT' => $checkpoint,
			'VERSION' => 1,
			'GRANTED' => 'N',
			'WAIT_SINCE' => $now,
			'DATE_CREATE' => $now,
			'DATE_MODIFY' => $now,
		]);
		if (!$added->isSuccess())
		{
			return null;
		}

		return $this->findWithinTransaction($mailboxId, $operationId);
	}

	private function grant(array $operation, ?array $control, ?string $expectedCheckpoint): Result
	{
		if (
			$control === null
			|| (string)$control['ENABLED'] !== 'Y'
			|| (string)$control['GRANTED'] === 'Y'
			|| (string)$operation['EXEC_STATE'] !== MigrationOperationTable::STATE_WAITING
			|| $expectedCheckpoint === null
			|| !in_array($expectedCheckpoint, self::CHECKPOINTS, true)
			|| (string)$control['CHECKPOINT'] !== $expectedCheckpoint
		)
		{
			return $this->error(self::ERROR_CONFLICT, 'The manual migration checkpoint has changed');
		}

		$now = new DateTime();
		$control = $this->updateControl($control, [
			'GRANTED' => 'Y',
			'WAIT_SINCE' => null,
			'RETRY_RESUMED_AT' => $now,
		]);
		$scheduled = MigrationOperationTable::update((int)$operation['ID'], [
			'EXEC_STATE' => MigrationOperationTable::STATE_SCHEDULED,
			'AUTO_RETRY' => 'Y',
			'STALLED_ATTEMPTS' => 0,
			'NEXT_RUN' => $now,
			'DATE_MODIFY' => $now,
		]);
		if (!$scheduled->isSuccess())
		{
			throw new \RuntimeException('The migration operation was not scheduled');
		}

		return (new Result())->setData(['control' => $control]);
	}

	private function automatic(array $operation, ?array $control): Result
	{
		if ($control === null || (string)$control['ENABLED'] !== 'Y')
		{
			return $this->error(self::ERROR_CONFLICT, 'The migration operation is already automatic');
		}

		$control = $this->setAutomatic($control, $operation, true);

		return (new Result())->setData(['control' => $control]);
	}

	private function setAutomatic(array $control, array $operation, bool $restoreSchedule): array
	{
		$now = new DateTime();
		$control = $this->updateControl($control, [
			'ENABLED' => 'N',
			'GRANTED' => 'N',
			'WAIT_SINCE' => null,
			'RETRY_RESUMED_AT' => $now,
		]);
		$fields = [
			'STALLED_ATTEMPTS' => 0,
			'DATE_MODIFY' => $now,
		];
		if (
			$restoreSchedule
			&& (string)$operation['EXEC_STATE'] === MigrationOperationTable::STATE_WAITING
			&& empty($operation['LAST_ERROR_CODE'])
		)
		{
			$fields += [
				'EXEC_STATE' => MigrationOperationTable::STATE_SCHEDULED,
				'AUTO_RETRY' => 'Y',
				'NEXT_RUN' => $now,
			];
		}
		$scheduled = MigrationOperationTable::update((int)$operation['ID'], $fields);
		if (!$scheduled->isSuccess())
		{
			throw new \RuntimeException('The migration operation was not scheduled');
		}

		return $control;
	}

	private function moveToCheckpoint(array $control, string $checkpoint, bool $waiting): array
	{
		return $this->updateControl($control, [
			'CHECKPOINT' => $checkpoint,
			'GRANTED' => 'N',
			'WAIT_SINCE' => $waiting ? new DateTime() : null,
		]);
	}

	private function markWaiting(array $control): array
	{
		return $this->updateControl($control, ['WAIT_SINCE' => new DateTime()], false);
	}

	private function updateControl(array $control, array $fields, bool $incrementVersion = true): array
	{
		$fields['DATE_MODIFY'] = new DateTime();
		if ($incrementVersion)
		{
			$fields['VERSION'] = (int)$control['VERSION'] + 1;
		}
		$updated = MigrationManualControlTable::update((int)$control['ID'], $fields);
		if (!$updated->isSuccess())
		{
			throw new \RuntimeException('The manual migration control state was not saved');
		}

		return $this->findWithinTransaction((int)$control['MAILBOX_ID'], (string)$control['OPERATION_ID'])
			?? throw new \RuntimeException('The manual migration control state was lost');
	}

	private function scheduleWaitingOperation(array $operation): void
	{
		$now = new DateTime();
		$scheduled = MigrationOperationTable::update((int)$operation['ID'], [
			'EXEC_STATE' => MigrationOperationTable::STATE_SCHEDULED,
			'AUTO_RETRY' => 'Y',
			'NEXT_RUN' => $now,
			'DATE_MODIFY' => $now,
		]);
		if (!$scheduled->isSuccess())
		{
			throw new \RuntimeException('The migration operation was not scheduled');
		}
	}

	private function findAddressedOperation(array $state, string $operationId): ?array
	{
		$operation = $state['operations'][0] ?? null;

		return is_array($operation) && (string)$operation['OPERATION_ID'] === $operationId ? $operation : null;
	}

	private function findWithinTransaction(int $mailboxId, string $operationId): ?array
	{
		$connection = Application::getConnection();
		$row = $connection->query(sprintf(
			"SELECT * FROM %s WHERE MAILBOX_ID = %u AND OPERATION_ID = '%s' FOR UPDATE",
			MigrationManualControlTable::getTableName(),
			$mailboxId,
			$connection->getSqlHelper()->forSql($operationId),
		))->fetch();

		return $row ?: null;
	}

	private function findCurrent(int $mailboxId, string $operationId, array $select = ['*']): ?array
	{
		$row = MigrationManualControlTable::query()
			->setSelect($select)
			->where('MAILBOX_ID', $mailboxId)
			->where('OPERATION_ID', $operationId)
			->setLimit(1)
			->fetch()
		;

		return $row ?: null;
	}

	private function automaticState(?array $control): Result
	{
		return (new Result())->setData([
			'manualControlled' => false,
			'manualWaiting' => false,
			'manualVersion' => $control === null ? 0 : (int)$control['VERSION'],
			'manualRetryResumedAt' => $control !== null && $control['RETRY_RESUMED_AT'] instanceof DateTime
				? $control['RETRY_RESUMED_AT']
				: null,
		]);
	}

	private function isExecutable(array $operation): bool
	{
		return !in_array((string)$operation['EXEC_STATE'], [
			MigrationOperationTable::STATE_DONE,
			MigrationOperationTable::STATE_CANCELLED,
			MigrationOperationTable::STATE_CANCELLING,
			MigrationOperationTable::STATE_NEEDS_ATTENTION,
		], true);
	}

	private function isSchemaReady(): bool
	{
		try
		{
			return $this->schema->isReady();
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return false;
		}
	}

	private function schemaExists(): bool
	{
		return Application::getConnection()->isTableExists(MigrationManualControlTable::getTableName());
	}

	/**
	 * True while the control table can hold a row that matters: the mode is on, or it was on and
	 * left rows behind. Neither is the case on a portal that only took the update, and the pass of
	 * the agent then never opens the table.
	 */
	private function mayHoldState(): bool
	{
		return self::isEnabled() || Option::get('mail', self::OPTION_TOUCHED, 'N') === 'Y';
	}

	private function rememberStoredState(): void
	{
		if (Option::get('mail', self::OPTION_TOUCHED, 'N') !== 'Y')
		{
			Option::set('mail', self::OPTION_TOUCHED, 'Y');
		}
	}

	private function markPublicationPending(array $state, string $operationId): void
	{
		$operation = $this->findAddressedOperation($state, $operationId);
		if ($operation === null)
		{
			throw new \RuntimeException('The migration operation is unavailable');
		}

		$updated = MigrationOperationTable::update((int)$operation['ID'], [
			'PUBLICATION_PENDING' => 'Y',
			'DATE_MODIFY' => new DateTime(),
		]);
		if (!$updated->isSuccess())
		{
			throw new \RuntimeException('The migration status publication was not scheduled');
		}
	}

	/** Built on demand: the publisher reads the status, and that reader owns a control of its own. */
	private function publisher(): MigrationUserStatusPublisher
	{
		return $this->publisher ??= new MigrationUserStatusPublisher();
	}

	/** Built on demand: the tracker builds a control of its own on the paths of the agent. */
	private function tracker(): OperationTracker
	{
		return $this->tracker ??= new OperationTracker();
	}

	private function error(string $code, string $message): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}
}
