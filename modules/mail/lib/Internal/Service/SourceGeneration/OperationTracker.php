<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Internal\Service\Mailbox\SettingsWriteCoordinator;
use Bitrix\Mail\Internals\MigrationOperationTable;
use Bitrix\Main\Application;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Persistence boundary of the migration scheduler.
 */
final class OperationTracker
{
	private const RUN_LEASE_SECONDS = 300;

	public function find(int $mailboxId, string $operationId): ?array
	{
		$row = MigrationOperationTable::getList([
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'=OPERATION_ID' => $operationId,
			],
			'limit' => 1,
		])->fetch();

		return $row ?: null;
	}

	/** Call after locking the mailbox and any generations needed by the caller; never lock generations afterward. */
	public function findWithinTransaction(int $mailboxId, string $operationId): ?array
	{
		$connection = Application::getConnection();
		$row = $connection->query(sprintf(
			"SELECT * FROM %s WHERE MAILBOX_ID = %u AND OPERATION_ID = '%s' FOR UPDATE",
			MigrationOperationTable::getTableName(),
			$mailboxId,
			$connection->getSqlHelper()->forSql($operationId),
		))->fetch();
		if (!$row)
		{
			return null;
		}

		foreach ($row as $field => $value)
		{
			$row[$field] = SettingsWriteCoordinator::decodeStoredField(
				MigrationOperationTable::class,
				$field,
				$value,
			);
		}

		return $row;
	}

	public function findLatestForMailbox(int $mailboxId): ?array
	{
		$row = MigrationOperationTable::getList([
			'filter' => ['=MAILBOX_ID' => $mailboxId],
			'order' => ['ID' => 'DESC'],
			'limit' => 1,
		])->fetch();

		return $row ?: null;
	}

	/** @return array<int, array<string, mixed>> Rows indexed by mailbox id. */
	public function findLatestForMailboxes(array $mailboxIds, array $select = ['*']): array
	{
		$mailboxIds = array_values(array_unique(array_filter(array_map('intval', $mailboxIds))));
		if ($mailboxIds === [])
		{
			return [];
		}
		if (!$this->hasUnaliasedField($select, 'MAILBOX_ID') && !in_array('*', $select, true))
		{
			array_unshift($select, 'MAILBOX_ID');
		}

		$latestIds = MigrationOperationTable::getList([
			'select' => ['MAILBOX_ID', 'LATEST_ID'],
			'filter' => ['@MAILBOX_ID' => $mailboxIds],
			'runtime' => [new ExpressionField('LATEST_ID', 'MAX(%s)', 'ID')],
		])->fetchAll();
		if ($latestIds === [])
		{
			return [];
		}

		$rows = MigrationOperationTable::getList([
			'select' => $select,
			'filter' => [
				'@ID' => array_map('intval', array_column($latestIds, 'LATEST_ID')),
			],
		])->fetchAll();

		$latest = [];
		foreach ($rows as $row)
		{
			$mailboxId = (int)$row['MAILBOX_ID'];
			$latest[$mailboxId] ??= $row;
		}

		return $latest;
	}

	/** @return array<int, array<string, mixed>> */
	public function ready(int $limit, ?DateTime $now = null): array
	{
		$now ??= new DateTime();

		return MigrationOperationTable::getList([
			'filter' => [
				'@EXEC_STATE' => [
					MigrationOperationTable::STATE_SCHEDULED,
					MigrationOperationTable::STATE_RUNNING,
					MigrationOperationTable::STATE_WAITING,
					MigrationOperationTable::STATE_CANCELLING,
				],
				'=AUTO_RETRY' => 'Y',
				'<=NEXT_RUN' => $now,
			],
			'order' => ['NEXT_RUN' => 'ASC', 'ID' => 'ASC'],
			'limit' => max(1, $limit),
		])->fetchAll();
	}

	public function wakeStoppedOperations(int $limit, ?DateTime $now = null): void
	{
		if ($limit <= 0)
		{
			return;
		}

		$now ??= new DateTime();
		$operationIds = array_map(
			'intval',
			array_column(
				MigrationOperationTable::getList([
					'select' => ['ID'],
					'filter' => [[
						'LOGIC' => 'OR',
						[
							'@EXEC_STATE' => [
								MigrationOperationTable::STATE_SCHEDULED,
								MigrationOperationTable::STATE_RUNNING,
								MigrationOperationTable::STATE_WAITING,
								MigrationOperationTable::STATE_CANCELLING,
							],
							'=AUTO_RETRY' => 'Y',
							'<=NEXT_RUN' => $now,
						],
						[
							'=EXEC_STATE' => MigrationOperationTable::STATE_WAITING,
							'=AUTO_RETRY' => 'Y',
							'=LAST_ERROR_CODE' => MigrationService::ERROR_MIGRATION_STOPPED,
							'>NEXT_RUN' => $now,
						],
					]],
					'order' => ['NEXT_RUN' => 'ASC', 'ID' => 'ASC'],
					'limit' => $limit,
				])->fetchAll(),
				'ID',
			),
		);
		if ($operationIds === [])
		{
			return;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$nowSql = $helper->convertToDbDateTime($now);

		$connection->queryExecute(sprintf(
			"UPDATE %s SET NEXT_RUN = %s, DATE_MODIFY = %s"
			. ' WHERE ID IN (%s)'
			. " AND EXEC_STATE = '%s' AND AUTO_RETRY = 'Y'"
			. " AND LAST_ERROR_CODE = '%s' AND NEXT_RUN > %s",
			MigrationOperationTable::getTableName(),
			$nowSql,
			$nowSql,
			implode(', ', $operationIds),
			$helper->forSql(MigrationOperationTable::STATE_WAITING),
			$helper->forSql(MigrationService::ERROR_MIGRATION_STOPPED),
			$nowSql,
		));
	}

	/** @return array<int, array<string, mixed>> */
	public function pendingPublications(int $limit): array
	{
		return MigrationOperationTable::getList([
			'filter' => ['=PUBLICATION_PENDING' => 'Y'],
			'order' => ['ID' => 'ASC'],
			'limit' => max(1, $limit),
		])->fetchAll();
	}

	public function acceptSnapshot(
		int $mailboxId,
		string $operationId,
		int $generationId,
		MigrationStage $stage,
		string $migratorService,
	): bool
	{
		return $this->writeStateLocked($mailboxId, function () use (
			$mailboxId,
			$operationId,
			$generationId,
			$stage,
			$migratorService,
		): bool {
			$now = new DateTime();
			$existing = $this->findWithinTransaction($mailboxId, $operationId);
			$fields = [
				'GENERATION_ID' => $generationId,
				'STAGE' => $stage->value,
				'SNAPSHOT_ACCEPTED' => 'Y',
				'ATTEMPTS' => 0,
				'STALLED_ATTEMPTS' => 0,
				'LAST_PROGRESS' => $now,
				'LAST_ERROR_CODE' => null,
				'ERROR_OWNER' => null,
				'MIGRATOR_SERVICE' => $migratorService !== '' ? $migratorService : null,
				'DATE_MODIFY' => $now,
			];
			if ($existing === null || $stage->hasNowhereToGo() || !$this->isWaitingForManualGrant($existing))
			{
				$fields += [
					'EXEC_STATE' => $stage->hasNowhereToGo()
						? MigrationOperationTable::STATE_DONE
						: MigrationOperationTable::STATE_SCHEDULED,
					'AUTO_RETRY' => $stage->hasNowhereToGo() ? 'N' : 'Y',
					'NEXT_RUN' => $stage->hasNowhereToGo() ? null : $now,
				];
			}

			if ($existing === null)
			{
				$added = MigrationOperationTable::add($fields + [
					'MAILBOX_ID' => $mailboxId,
					'OPERATION_ID' => $operationId,
					'SWITCH_AUTHORIZED' => 'N',
					'CANCEL_REQUESTED' => 'N',
					'DATE_CREATE' => $now,
				]);
				if (!$added->isSuccess())
				{
					return false;
				}

				return (new ManualMigrationControl())->armNewOperationUnderTransaction(
					$mailboxId,
					$operationId,
					$stage,
				);
			}

			if (
				(
					(string)$existing['EXEC_STATE'] === MigrationOperationTable::STATE_CANCELLED
					|| (string)$existing['CANCEL_REQUESTED'] === 'Y'
				)
			)
			{
				return true;
			}

			return MigrationOperationTable::update((int)$existing['ID'], $fields)->isSuccess();
		});
	}

	/**
	 * @return array{success: bool, changed: bool, operation: ?array}
	 */
	public function authorizeSwitch(int $mailboxId, string $operationId, MigrationStage $stage): array
	{
		$result = null;
		$success = $this->writeStateLocked(
			$mailboxId,
			function () use ($mailboxId, $operationId, $stage, &$result): bool {
				$result = $this->authorizeSwitchUnderLock($mailboxId, $operationId, $stage);

				return $result['success'];
			},
		);

		return $success && is_array($result)
			? $result
			: ['success' => false, 'changed' => false, 'operation' => null];
	}

	/**
	 * Joins the caller transaction after the shared state locks; never commits or publishes.
	 * @return array{success: bool, changed: bool, operation: ?array}
	 */
	public function authorizeSwitchUnderLock(
		int $mailboxId,
		string $operationId,
		MigrationStage $stage,
	): array
	{
		$operation = $this->findWithinTransaction($mailboxId, $operationId);
		if ($operation === null)
		{
			return ['success' => false, 'changed' => false, 'operation' => null];
		}

		$changed = (string)$operation['SWITCH_AUTHORIZED'] !== 'Y';
		$now = new DateTime();
		$fields = [
			'SWITCH_AUTHORIZED' => 'Y',
			'STAGE' => $stage->value,
			'DATE_MODIFY' => $now,
		];
		if ($changed)
		{
			$fields['PUBLICATION_PENDING'] = 'Y';
			$fields['NOTIFICATION_PENDING'] = 'Y';
		}

		$execState = (string)$operation['EXEC_STATE'];
		$manualWaiting = $this->isWaitingForManualGrant($operation);
		if (
			$changed
			&& $execState === MigrationOperationTable::STATE_NEEDS_ATTENTION
			&& RetryPolicy::canResumeAfterSwitchAuthorization($operation['LAST_ERROR_CODE'] ?? null)
		)
		{
			$fields['EXEC_STATE'] = MigrationOperationTable::STATE_SCHEDULED;
			$fields['AUTO_RETRY'] = 'Y';
			$fields['NEXT_RUN'] = $now;
			$fields['STALLED_ATTEMPTS'] = 0;
			$fields['LAST_PROGRESS'] = $now;
		}
		elseif (!in_array($execState, [
			MigrationOperationTable::STATE_DONE,
			MigrationOperationTable::STATE_CANCELLED,
			MigrationOperationTable::STATE_CANCELLING,
			MigrationOperationTable::STATE_NEEDS_ATTENTION,
		], true) && !$manualWaiting)
		{
			$fields['EXEC_STATE'] = MigrationOperationTable::STATE_SCHEDULED;
			$fields['AUTO_RETRY'] = 'Y';
			$fields['NEXT_RUN'] = $now;
		}

		$success = MigrationOperationTable::update((int)$operation['ID'], $fields)->isSuccess();

		return [
			'success' => $success,
			'changed' => $success && $changed,
			'operation' => $success ? $this->findWithinTransaction($mailboxId, $operationId) : null,
		];
	}

	/** @return array{success: bool, changed: bool, operation: ?array} */
	public function requestCancellation(
		int $mailboxId,
		string $operationId,
		int $generationId,
		MigrationStage $stage,
		bool $switchWasAuthorized = false,
	): array
	{
		$changed = false;
		$success = $this->writeStateLocked($mailboxId, function () use (
			$mailboxId,
			$operationId,
			$generationId,
			$stage,
			$switchWasAuthorized,
			&$changed,
		): bool {
			$result = $this->requestCancellationUnderLock(
				$mailboxId,
				$operationId,
				$generationId,
				$stage,
				$switchWasAuthorized,
			);
			$changed = $result['changed'];

			return $result['success'];
		});

		return [
			'success' => $success,
			'changed' => $success && $changed,
			'operation' => $success ? $this->find($mailboxId, $operationId) : null,
		];
	}

	/**
	 * Joins the caller transaction after the shared state locks; never commits or publishes.
	 * @return array{success: bool, changed: bool, operation: ?array}
	 */
	public function requestCancellationUnderLock(
		int $mailboxId,
		string $operationId,
		int $generationId,
		MigrationStage $stage,
		bool $switchWasAuthorized,
	): array
	{
		$changed = false;
		$now = new DateTime();
		$operation = $this->findWithinTransaction($mailboxId, $operationId);
		if ($operation === null)
		{
			$added = MigrationOperationTable::add([
				'MAILBOX_ID' => $mailboxId,
				'OPERATION_ID' => $operationId,
				'GENERATION_ID' => $generationId,
				'EXEC_STATE' => MigrationOperationTable::STATE_CANCELLING,
				'STAGE' => $stage->value,
				'SNAPSHOT_ACCEPTED' => 'Y',
				'SWITCH_AUTHORIZED' => $switchWasAuthorized ? 'Y' : 'N',
				'CANCEL_REQUESTED' => 'Y',
				'AUTO_RETRY' => 'Y',
				'PUBLICATION_PENDING' => 'Y',
				'ATTEMPTS' => 0,
				'STALLED_ATTEMPTS' => 0,
				'NEXT_RUN' => $now,
				'LAST_PROGRESS' => $now,
				'DATE_CREATE' => $now,
				'DATE_MODIFY' => $now,
			]);
			$changed = $added->isSuccess();
			if ($changed)
			{
				(new ManualMigrationControl())->invalidateForCancellationUnderLock($mailboxId, $operationId);
			}

			return [
				'success' => $changed,
				'changed' => $changed,
				'operation' => $changed ? $this->findWithinTransaction($mailboxId, $operationId) : null,
			];
		}

		if ((string)$operation['EXEC_STATE'] === MigrationOperationTable::STATE_CANCELLED)
		{
			return ['success' => true, 'changed' => false, 'operation' => $operation];
		}

		$switchWasAuthorized = $switchWasAuthorized || (string)$operation['SWITCH_AUTHORIZED'] === 'Y';
		$changed = (string)$operation['CANCEL_REQUESTED'] !== 'Y'
			|| (string)$operation['EXEC_STATE'] !== MigrationOperationTable::STATE_CANCELLING
			|| (int)$operation['GENERATION_ID'] !== $generationId
			|| (string)$operation['SWITCH_AUTHORIZED'] !== ($switchWasAuthorized ? 'Y' : 'N');
		$fields = [
			'GENERATION_ID' => $generationId,
			'SNAPSHOT_ACCEPTED' => 'Y',
			'SWITCH_AUTHORIZED' => $switchWasAuthorized ? 'Y' : 'N',
			'CANCEL_REQUESTED' => 'Y',
			'EXEC_STATE' => MigrationOperationTable::STATE_CANCELLING,
			'STAGE' => $stage->value,
			'AUTO_RETRY' => 'Y',
			'NEXT_RUN' => $now,
			'LAST_ERROR_CODE' => null,
			'ERROR_OWNER' => null,
			'DATE_MODIFY' => $now,
		];

		if ($changed)
		{
			$fields['ATTEMPTS'] = 0;
			$fields['STALLED_ATTEMPTS'] = 0;
			$fields['LAST_PROGRESS'] = $now;
			$fields['PUBLICATION_PENDING'] = 'Y';
		}

		$success = MigrationOperationTable::update((int)$operation['ID'], $fields)->isSuccess();
		if ($success && $changed)
		{
			(new ManualMigrationControl())->invalidateForCancellationUnderLock($mailboxId, $operationId);
		}

		return [
			'success' => $success,
			'changed' => $success && $changed,
			'operation' => $success ? $this->findWithinTransaction($mailboxId, $operationId) : null,
		];
	}

	public function claim(array $operation, ?DateTime $now = null): ?array
	{
		$mailboxId = (int)$operation['MAILBOX_ID'];
		$now ??= new DateTime();

		$claimed = $this->withLock($mailboxId, static function () use ($operation, $now): ?array {
			$current = MigrationOperationTable::getByPrimary((int)$operation['ID'])->fetch();
			if (!$current || (string)$current['AUTO_RETRY'] !== 'Y')
			{
				return null;
			}

			$state = (string)$current['EXEC_STATE'];
			if (!in_array($state, [
				MigrationOperationTable::STATE_SCHEDULED,
				MigrationOperationTable::STATE_RUNNING,
				MigrationOperationTable::STATE_WAITING,
				MigrationOperationTable::STATE_CANCELLING,
			], true))
			{
				return null;
			}

			$nextRun = $current['NEXT_RUN'] ?? null;
			if ($nextRun instanceof DateTime && $nextRun->getTimestamp() > $now->getTimestamp())
			{
				return null;
			}

			$leaseUntil = DateTime::createFromTimestamp($now->getTimestamp() + self::RUN_LEASE_SECONDS);
			$claimedState = $state === MigrationOperationTable::STATE_CANCELLING
				? MigrationOperationTable::STATE_CANCELLING
				: MigrationOperationTable::STATE_RUNNING;

			$updated = MigrationOperationTable::update((int)$current['ID'], [
				'EXEC_STATE' => $claimedState,
				'ATTEMPTS' => (int)$current['ATTEMPTS'] + 1,
				'NEXT_RUN' => $leaseUntil,
				'DATE_MODIFY' => $now,
			]);

			if (!$updated->isSuccess())
			{
				return null;
			}

			$claimed = MigrationOperationTable::getByPrimary((int)$current['ID'])->fetch() ?: null;
			if ($claimed !== null)
			{
				$claimed['MANUAL_VERSION'] = (new ManualMigrationControl())->readVersion(
					(int)$current['MAILBOX_ID'],
					(string)$current['OPERATION_ID'],
				);
			}

			return $claimed;
		});

		return is_array($claimed) ? $claimed : null;
	}

	public function recordOutcome(
		array $operation,
		array $decision,
		bool $publish = false,
		bool $notify = false,
		?int $manualVersion = null,
		bool $consumeManualRetryResume = false,
	): bool
	{
		$mailboxId = (int)$operation['MAILBOX_ID'];

		return $this->writeStateLocked($mailboxId, function () use (
			$operation,
			$decision,
			$publish,
			$notify,
			$manualVersion,
			$consumeManualRetryResume,
		): bool {
			$current = $this->findWithinTransaction((int)$operation['MAILBOX_ID'], (string)$operation['OPERATION_ID']);
			if (!$current)
			{
				return false;
			}

			if (
				(string)$operation['CANCEL_REQUESTED'] !== 'Y'
				&& (string)$current['CANCEL_REQUESTED'] === 'Y'
			)
			{
				return true;
			}

			$manualControl = new ManualMigrationControl();
			if (!$manualControl->matchesVersionUnderTransaction(
				(int)$operation['MAILBOX_ID'],
				(string)$operation['OPERATION_ID'],
				$manualVersion,
			))
			{
				return true;
			}

			if (
				(string)$operation['SWITCH_AUTHORIZED'] !== 'Y'
				&& (string)$current['SWITCH_AUTHORIZED'] === 'Y'
			)
			{
				return true;
			}

			$fields = [
				'EXEC_STATE' => $decision['execState'],
				'STAGE' => $decision['stage']->value,
				'AUTO_RETRY' => $decision['autoRetry'] ? 'Y' : 'N',
				'STALLED_ATTEMPTS' => $decision['stalledAttempts'],
				'NEXT_RUN' => $decision['nextRun'],
				'LAST_PROGRESS' => $decision['lastProgress'],
				'LAST_ERROR_CODE' => $decision['errorCode'],
				'ERROR_OWNER' => $decision['errorOwner'],
				'DATE_MODIFY' => new DateTime(),
			];
			if ($publish)
			{
				$fields['PUBLICATION_PENDING'] = 'Y';
				if ($notify)
				{
					$fields['NOTIFICATION_PENDING'] = 'Y';
				}
			}

			if (!MigrationOperationTable::update((int)$operation['ID'], $fields)->isSuccess())
			{
				return false;
			}
			if ($consumeManualRetryResume)
			{
				$manualControl->consumeRetryResumeUnderTransaction(
					(int)$operation['MAILBOX_ID'],
					(string)$operation['OPERATION_ID'],
					$manualVersion,
				);
			}

			return true;
		});
	}

	public function markCancelled(
		int $mailboxId,
		string $operationId,
		MigrationStage $stage,
		bool $publish = false,
		bool $notify = false,
	): bool
	{
		return $this->writeStateLocked($mailboxId, function () use (
			$mailboxId,
			$operationId,
			$stage,
			$publish,
			$notify,
		): bool {
			$operation = $this->findWithinTransaction($mailboxId, $operationId);
			if ($operation === null)
			{
				return false;
			}

			$fields = [
				'EXEC_STATE' => MigrationOperationTable::STATE_CANCELLED,
				'STAGE' => $stage->value,
				'AUTO_RETRY' => 'N',
				'NEXT_RUN' => null,
				'LAST_ERROR_CODE' => null,
				'ERROR_OWNER' => null,
				'DATE_MODIFY' => new DateTime(),
			];
			if ($publish)
			{
				$fields['PUBLICATION_PENDING'] = 'Y';
				if ($notify)
				{
					$fields['NOTIFICATION_PENDING'] = 'Y';
				}
			}

			return MigrationOperationTable::update((int)$operation['ID'], $fields)->isSuccess();
		});
	}

	public function publishPending(
		int $mailboxId,
		string $operationId,
		MigrationUserStatusPublisher $publisher,
	): bool
	{
		return $this->writeLocked($mailboxId, function () use ($mailboxId, $operationId, $publisher): bool {
			$operation = $this->find($mailboxId, $operationId);
			if ($operation === null || (string)$operation['PUBLICATION_PENDING'] !== 'Y')
			{
				return true;
			}

			$publisher->publish(
				$mailboxId,
				$operationId,
				(string)$operation['NOTIFICATION_PENDING'] === 'Y',
			);

			return MigrationOperationTable::update((int)$operation['ID'], [
				'PUBLICATION_PENDING' => 'N',
				'NOTIFICATION_PENDING' => 'N',
				'DATE_MODIFY' => new DateTime(),
			])->isSuccess();
		});
	}

	/**
	 * Locks mailbox before the addressed operation. These callbacks never read or lock generations.
	 * Publication must follow this method's successful return.
	 */
	private function writeStateLocked(int $mailboxId, callable $write): bool
	{
		return (bool)$this->withLock($mailboxId, static function () use ($mailboxId, $write): bool {
			$result = (new SettingsWriteCoordinator())->execute(
				$mailboxId,
				static function () use ($write): Result {
					return $write()
						? new Result()
						: (new Result())->addError(new Error(
							'The migration operation state was not saved',
							SettingsWriteCoordinator::ERROR_WRITE_FAILED,
						));
				},
			);

			return $result->isSuccess();
		});
	}

	private function hasUnaliasedField(array $select, string $field): bool
	{
		foreach ($select as $alias => $selectedField)
		{
			if ((is_int($alias) && $selectedField === $field) || $alias === $field)
			{
				return true;
			}
		}

		return false;
	}

	private function isWaitingForManualGrant(array $operation): bool
	{
		$mailboxId = (int)($operation['MAILBOX_ID'] ?? 0);

		return $mailboxId > 0
			&& isset((new ManualMigrationControl())->findWaitingMailboxes([$mailboxId => $operation])[$mailboxId]);
	}

	private function writeLocked(int $mailboxId, callable $write): bool
	{
		return (bool)$this->withLock($mailboxId, $write);
	}

	private function withLock(int $mailboxId, callable $write): mixed
	{
		if (!OperationLock::acquire($mailboxId))
		{
			return false;
		}

		try
		{
			return $write();
		}
		finally
		{
			OperationLock::release($mailboxId);
		}
	}
}
