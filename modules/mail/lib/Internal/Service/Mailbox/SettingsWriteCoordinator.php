<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Mailbox;

use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationOperationSchema;
use Bitrix\Mail\Internals\MailboxSourceGenerationTable;
use Bitrix\Mail\Internals\MigrationOperationTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Error;
use Bitrix\Main\Mail\Internal\SenderTable;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\Result;

final class SettingsWriteCoordinator
{
	public const ERROR_WRITE_FAILED = 'MAILBOX_SETTINGS_WRITE_FAILED';
	public const ERROR_MAILBOX_NOT_FOUND = 'MAILBOX_SETTINGS_MAILBOX_NOT_FOUND';

	/**
	 * Owns the transaction. Call only when the caller has not started one.
	 * The write callback receives the locked state and an after-commit callback list by reference.
	 */
	public function execute(
		int $mailboxId,
		callable $write,
		bool $withMigrationState = false,
		bool $checkSwitchedSource = false,
	): Result
	{
		$connection = Application::getConnection();
		$afterCommit = [];
		$started = false;
		try
		{
			$connection->startTransaction();
			$started = true;
			$locked = $this->lockWithinTransaction($mailboxId, $withMigrationState, checkSwitchedSource: $checkSwitchedSource);
			$result = $locked->isSuccess() ? $write($locked->getData(), $afterCommit) : $locked;
			if (!$result->isSuccess())
			{
				$started = false;
				$connection->rollbackTransaction();

				return $result;
			}
			$connection->commitTransaction();
			$started = false;
		}
		catch (\Throwable $exception)
		{
			if ($started)
			{
				$started = false;
				try
				{
					$connection->rollbackTransaction();
				}
				catch (\Throwable $rollbackException)
				{
					Application::getInstance()->getExceptionHandler()->writeToLog($rollbackException);
				}
			}
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return (new Result())->addError(new Error('The mailbox settings were not saved', self::ERROR_WRITE_FAILED));
		}

		$this->afterCommit($afterCommit);

		return $result;
	}

	/**
	 * Joins a caller-owned transaction; never commits, rolls back or acquires OperationLock.
	 * Migration callers must acquire OperationLock before entering this method.
	 * State contains only the latest operation. Generations require an operation ID and exclude unrelated history.
	 */
	public function lockWithinTransaction(
		int $mailboxId,
		bool $withMigrationState = false,
		?string $operationId = null,
		bool $checkSwitchedSource = false,
	): Result
	{
		$connection = Application::getConnection();
		$mailbox = $connection->query(sprintf(
			'SELECT * FROM %s WHERE ID = %u FOR UPDATE',
			MailboxTable::getTableName(),
			$mailboxId,
		))->fetch();
		if (!$mailbox)
		{
			return (new Result())->addError(new Error('The mailbox does not exist', self::ERROR_MAILBOX_NOT_FOUND));
		}
		$mailbox['OPTIONS'] = $this->readOptions($mailbox['OPTIONS'] ?? null);
		$state = ['mailbox' => $mailbox];
		if ($withMigrationState)
		{
			$state['sourceGenerationId'] = array_key_exists('ACTIVE_GENERATION_ID', $mailbox)
				? (int)$mailbox['ACTIVE_GENERATION_ID']
				: null;
			$state['generations'] = $state['sourceGenerationId'] === null || $operationId === null
				? []
				: $this->lockGenerations($connection, $mailboxId, $operationId);
			$state['hasSwitchedSource'] = $checkSwitchedSource && $state['sourceGenerationId'] !== null
				&& $this->hasSwitchedSource($connection, $mailboxId);
			$state['operations'] = (new MigrationOperationSchema($connection))->isReady()
				? $this->lockLatestOperation($connection, $mailboxId)
				: [];
		}

		return (new Result())->setData($state);
	}

	/** Call only after mailbox and all required generation/operation rows have been locked. */
	public function lockSendersWithinTransaction(int $mailboxId, ?array $mailbox = null): array
	{
		$connection = Application::getConnection();
		$filter = sprintf("(PARENT_MODULE_ID = 'mail' AND PARENT_ID = %u)", $mailboxId);
		if ($mailbox !== null)
		{
			$filter .= sprintf(
				" OR (EMAIL = '%s' AND USER_ID = %u)",
				$connection->getSqlHelper()->forSql((string)$mailbox['EMAIL']),
				(int)$mailbox['USER_ID'],
			);
		}
		$senders = $connection->query(sprintf(
			'SELECT * FROM %s WHERE %s ORDER BY ID FOR UPDATE',
			SenderTable::getTableName(),
			$filter,
		))->fetchAll();
		foreach ($senders as &$sender)
		{
			$sender['STORED_OPTIONS'] = $sender['OPTIONS'];
			$sender['OPTIONS'] = self::decodeStoredField(SenderTable::class, 'OPTIONS', $sender['OPTIONS']);
		}
		unset($sender);

		return $senders;
	}

	/** Decodes fetched DB fields, retaining the storage values of booleans used by ORM array reads. */
	public static function decodeStoredField(string $entity, string $fieldName, mixed $value): mixed
	{
		$field = $entity::getEntity()->getField($fieldName);
		if (
			!($field instanceof BooleanField)
			&& !is_callable($field->getConnection()->getSqlHelper()->getConverter($field))
		)
		{
			$value = $field->convertValueFromDb($value);
		}
		foreach ($field->getFetchDataModifiers() as $modifier)
		{
			$value = $modifier($value);
		}

		return $value;
	}

	/** Invoke only after the outer commit; discard callbacks after rollback. */
	public function afterCommit(array $callbacks): void
	{
		foreach ($callbacks as $callback)
		{
			try
			{
				$callback();
			}
			catch (\Throwable $exception)
			{
				Application::getInstance()->getExceptionHandler()->writeToLog($exception);
			}
		}
	}

	/** The mailbox lock serializes state writers; lock generations before operations and senders. */
	private function lockGenerations(Connection $connection, int $mailboxId, string $operationId): array
	{
		return $connection->query(sprintf(
			"SELECT * FROM %s WHERE MAILBOX_ID = %u AND (OPERATION_ID = '%s' OR STATUS IN ('%s', '%s')) ORDER BY ID FOR UPDATE",
			MailboxSourceGenerationTable::getTableName(),
			$mailboxId,
			$connection->getSqlHelper()->forSql($operationId),
			MailboxSourceGenerationTable::STATUS_ACTIVE,
			MailboxSourceGenerationTable::STATUS_PREPARING,
		))->fetchAll();
	}

	private function hasSwitchedSource(Connection $connection, int $mailboxId): bool
	{
		return (bool)$connection->query(sprintf(
			"SELECT ID FROM %s WHERE MAILBOX_ID = %u AND (STATUS = '%s' OR (STATUS = '%s' AND OPERATION_ID <> '%s')) ORDER BY ID LIMIT 1 FOR UPDATE",
			MailboxSourceGenerationTable::getTableName(),
			$mailboxId,
			MailboxSourceGenerationTable::STATUS_ARCHIVED,
			MailboxSourceGenerationTable::STATUS_ACTIVE,
			MailboxSourceGenerationTable::OPERATION_G1_BACKFILL,
		))->fetch();
	}

	private function lockLatestOperation(Connection $connection, int $mailboxId): array
	{
		return $connection->query(sprintf(
			'SELECT ID, MAILBOX_ID, OPERATION_ID, STAGE, EXEC_STATE, SWITCH_AUTHORIZED, AUTO_RETRY, NEXT_RUN, LAST_ERROR_CODE, ERROR_OWNER FROM %s WHERE MAILBOX_ID = %u ORDER BY ID DESC LIMIT 1 FOR UPDATE',
			MigrationOperationTable::getTableName(),
			$mailboxId,
		))->fetchAll();
	}

	private function readOptions(mixed $stored): array
	{
		if ($stored === null || $stored === '')
		{
			return [];
		}
		$options = unserialize((string)$stored, ['allowed_classes' => false]);
		if (!is_array($options))
		{
			throw new \UnexpectedValueException('The mailbox options are unreadable');
		}

		return $options;
	}
}
