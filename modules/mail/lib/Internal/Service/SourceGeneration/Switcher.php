<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Helper;
use Bitrix\Mail\Helper\MessageConversationStepper;
use Bitrix\Mail\Internal\Repository\MessageConversationListRepository;
use Bitrix\Mail\Internal\Service\Mailbox\SettingsWriteCoordinator;
use Bitrix\Mail\Internals\MailboxSourceGenerationTable;
use Bitrix\Mail\MailboxDirectory;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

final class Switcher
{
	public const ERROR_MAILBOX_LOCKED = 'MAIL_SOURCE_GENERATION_MAILBOX_LOCKED';
	public const ERROR_TARGET_UNKNOWN = 'MAIL_SOURCE_GENERATION_TARGET_UNKNOWN';
	public const ERROR_TARGET_NOT_PREPARING = 'MAIL_SOURCE_GENERATION_TARGET_NOT_PREPARING';
	public const ERROR_TARGET_REVISION_CONFLICT = 'MAIL_SOURCE_GENERATION_TARGET_REVISION_CONFLICT';
	public const ERROR_POINTER_CONFLICT = 'MAIL_SOURCE_GENERATION_POINTER_CONFLICT';
	public const ERROR_G1_NOT_READY = 'MAIL_SOURCE_GENERATION_G1_NOT_READY';
	public const ERROR_PROJECTION_FAILED = 'MAIL_SOURCE_GENERATION_PROJECTION_FAILED';
	public const ERROR_SWITCH_FAILED = 'MAIL_SOURCE_GENERATION_SWITCH_FAILED';

	public const POINTER_AS_STORED = -1;

	public const PROJECTED_FIELDS = ['SERVICE_ID', 'SERVER', 'PORT', 'USE_TLS', 'LOGIN', 'PASSWORD'];

	public function __construct(
		private readonly SwitchedCounters $counters = new SwitchedCounters(),
	)
	{
	}

	/**
	 * Owns the transaction and activates the generation prepared by the operation.
	 *
	 * @param int $expectedActiveGenerationId The pointer the caller saw; POINTER_AS_STORED
	 *                                        takes whatever the transaction reads.
	 * @param array|null $heldLock The mailbox lock {@see lockMailbox()} gave the caller and
	 *        the caller goes on holding: a migration takes it before the final
	 *        synchronization of the retained source and keeps it over the delta and this
	 *        switch, so that no synchronization of that source can finish in between and no
	 *        letter of it lands after the hand-over. The switch refreshes that lease by a
	 *        compare-and-set, returns the refreshed token and leaves it held by the caller.
	 * @return Result Data on success: ['generationId' => int, 'previousGenerationId' => int]
	 */
	public function switch(
		int $mailboxId,
		string $operationId,
		int $expectedActiveGenerationId = self::POINTER_AS_STORED,
		?array &$heldLock = null,
		?int $expectedTargetRevision = null,
	): Result
	{
		$result = new Result();

		if ($mailboxId <= 0 || $operationId === '')
		{
			return $result->addError(new Error(
				'A mailbox and an operation of the switch are expected',
				self::ERROR_TARGET_UNKNOWN,
			));
		}

		$ownsLock = $heldLock === null;
		$lock = $ownsLock ? $this->lockMailbox($mailboxId) : $heldLock;
		if ($lock === null)
		{
			return $result->addError(new Error(
				sprintf('The mailbox %u is busy with a synchronization', $mailboxId),
				self::ERROR_MAILBOX_LOCKED,
			));
		}

		$connection = Application::getConnection();
		$originalLock = $lock;
		$connection->startTransaction();
		try
		{
			$result = $this->activateWithinTransaction(
				$mailboxId,
				$operationId,
				$expectedActiveGenerationId,
				$lock,
				$expectedTargetRevision,
			);
			if (!$result->isSuccess())
			{
				$this->rollback($connection);
				$lock = $originalLock;
			}
			else
			{
				$connection->commitTransaction();
			}
		}
		catch (\Throwable)
		{
			$this->rollback($connection);
			$lock = $originalLock;
			$result = (new Result())->addError(new Error(
				'The generation switch failed',
				self::ERROR_SWITCH_FAILED,
			));
		}
		finally
		{
			if ($ownsLock)
			{
				$this->unlockMailbox($mailboxId, $lock);
			}
		}

		if (!$result->isSuccess())
		{
			return $result;
		}

		if (!$ownsLock)
		{
			$heldLock = $lock;
		}

		$this->afterCommit($mailboxId);

		return $result;
	}

	/** Joins the caller transaction; the caller owns commit, rollback and afterCommit. */
	public function activateWithinTransaction(
		int $mailboxId,
		string $operationId,
		int $expectedActiveGenerationId,
		array &$heldLock,
		?int $expectedTargetRevision = null,
	): Result
	{
		$result = new Result();
		if ($mailboxId <= 0 || $operationId === '')
		{
			return $result->addError(new Error(
				'A mailbox and an operation of the switch are expected',
				self::ERROR_TARGET_UNKNOWN,
			));
		}

		$lock = $this->renewMailbox($mailboxId, $heldLock);
		if ($lock === null || !$this->holdsMailbox($mailboxId, $lock))
		{
			return $result->addError(new Error(
				sprintf('The mailbox %u is busy with a synchronization', $mailboxId),
				self::ERROR_MAILBOX_LOCKED,
			));
		}
		$heldLock = $lock;

		return $this->applyWithinTransaction(
			$mailboxId,
			$operationId,
			$expectedActiveGenerationId,
			$expectedTargetRevision,
		);
	}

	public function afterCommit(int $mailboxId): void
	{
		$this->invalidateGenerationCaches($mailboxId);
		$this->counters->recount($mailboxId);
		MessageConversationStepper::bind();
	}

	/**
	 * The generation the pointer of the mailbox names right now, 0 for a mailbox
	 * without a completed G1.
	 */
	public function getActiveGenerationId(int $mailboxId): int
	{
		return (int)Application::getConnection()->queryScalar(sprintf(
			'SELECT ACTIVE_GENERATION_ID FROM b_mail_mailbox WHERE ID = %u',
			$mailboxId,
		));
	}

	/**
	 * Whether the legacy connection fields of the mailbox carry the snapshot of its
	 * active generation. The second half of a confirmed switch: without it the pointer
	 * names one source and the delivery still talks to another.
	 */
	public function isProjectionConfirmed(int $mailboxId, int $generationId): bool
	{
		$snapshot = $this->fetchConnectionSnapshot($generationId);
		$mailbox = MailboxTable::getList([
			'select' => self::PROJECTED_FIELDS,
			'filter' => ['=ID' => $mailboxId],
		])->fetch();

		if ($snapshot === null || !$mailbox)
		{
			return false;
		}

		foreach (self::PROJECTED_FIELDS as $field)
		{
			if ((string)$mailbox[$field] !== (string)$snapshot[$field])
			{
				return false;
			}
		}

		return true;
	}

	private function applyWithinTransaction(
		int $mailboxId,
		string $operationId,
		int $expectedActiveGenerationId,
		?int $expectedTargetRevision,
	): Result
	{
		$result = new Result();
		$connection = Application::getConnection();

		$pointer = $this->lockPointer($connection, $mailboxId);
		if ($pointer <= 0)
		{
			return $this->reject(self::ERROR_G1_NOT_READY, sprintf(
				'The mailbox %u has no completed first generation',
				$mailboxId,
			));
		}

		if ($expectedActiveGenerationId !== self::POINTER_AS_STORED && $expectedActiveGenerationId !== $pointer)
		{
			return $this->reject(self::ERROR_POINTER_CONFLICT, sprintf(
				'The active generation of the mailbox %u is %u, not the expected %u',
				$mailboxId,
				$pointer,
				$expectedActiveGenerationId,
			));
		}

		$target = $this->lockTarget($connection, $mailboxId, $operationId);
		if ($target === null)
		{
			return $this->reject(self::ERROR_TARGET_UNKNOWN, sprintf(
				'The operation %s has prepared no generation of the mailbox %u',
				$operationId,
				$mailboxId,
			));
		}

		$targetId = (int)$target['ID'];

		if ((string)$target['STATUS'] !== MailboxSourceGenerationTable::STATUS_PREPARING)
		{
			return $this->reject(self::ERROR_TARGET_NOT_PREPARING, sprintf(
				'The generation %u of the mailbox %u is %s, not prepared for a switch',
				$targetId,
				$mailboxId,
				(string)$target['STATUS'],
			));
		}

		$revision = (int)$target['REVISION'];
		if ($expectedTargetRevision !== null && $revision !== $expectedTargetRevision)
		{
			return $this->reject(self::ERROR_TARGET_REVISION_CONFLICT, sprintf(
				'The generation %u has revision %u, not the validated revision %u',
				$targetId,
				$revision,
				$expectedTargetRevision,
			));
		}

		$snapshot = $this->decodeConnectionSnapshot($target);
		if (!$this->compareAndSetPointer($connection, $mailboxId, $pointer, $targetId))
		{
			return $this->reject(self::ERROR_POINTER_CONFLICT, sprintf(
				'The active generation of the mailbox %u has changed during the switch',
				$mailboxId,
			));
		}

		(new MessageConversationListRepository())->setProjectionReady($mailboxId, false);

		$this->archiveOtherActiveGenerations($connection, $mailboxId, $targetId);

		if (!$this->activateTarget($connection, $targetId, $revision))
		{
			return $this->reject(self::ERROR_POINTER_CONFLICT, sprintf(
				'The generation %u has changed during the switch',
				$targetId,
			));
		}

		$projected = MailboxTable::update($mailboxId, $snapshot);
		if (!$projected->isSuccess())
		{
			return $this->reject(self::ERROR_PROJECTION_FAILED, sprintf(
				'The connection snapshot of the generation %u is not projected: %s',
				$targetId,
				MigrationMetrics::withoutSecretsOf($snapshot, implode('; ', $projected->getErrorMessages())),
			));
		}

		return $result->setData([
			'generationId' => $targetId,
			'previousGenerationId' => $pointer,
		]);
	}

	private function reject(string $code, string $message): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}

	private function rollback(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (\Throwable)
		{
			// An inner transaction of the ORM has already unwound this one
		}
	}

	/**
	 * The pointer of the mailbox, held until the end of the transaction: a concurrent
	 * switch waits here instead of racing the compare-and-set.
	 */
	private function lockPointer(Connection $connection, int $mailboxId): int
	{
		$row = $connection->query(sprintf(
			'SELECT ACTIVE_GENERATION_ID FROM b_mail_mailbox WHERE ID = %u FOR UPDATE',
			$mailboxId,
		))->fetch();

		return $row ? (int)$row['ACTIVE_GENERATION_ID'] : 0;
	}

	private function lockTarget(Connection $connection, int $mailboxId, string $operationId): ?array
	{
		$rows = $connection->query(sprintf(
			"SELECT %s FROM %s WHERE MAILBOX_ID = %u AND (OPERATION_ID = '%s' OR STATUS = '%s') ORDER BY ID FOR UPDATE",
			implode(', ', array_merge(['ID', 'OPERATION_ID', 'STATUS', 'REVISION'], self::PROJECTED_FIELDS)),
			MailboxSourceGenerationTable::getTableName(),
			$mailboxId,
			$connection->getSqlHelper()->forSql($operationId),
			MailboxSourceGenerationTable::STATUS_ACTIVE,
		))->fetchAll();
		foreach ($rows as $row)
		{
			if ((string)$row['OPERATION_ID'] === $operationId)
			{
				return $row;
			}
		}

		return null;
	}

	/**
	 * The password of the snapshot comes back through the existing decryption of the
	 * generation entity and goes into the mailbox through its existing encryption.
	 *
	 * @return array<string, mixed>|null
	 */
	private function fetchConnectionSnapshot(int $generationId): ?array
	{
		$row = MailboxSourceGenerationTable::getList([
			'select' => self::PROJECTED_FIELDS,
			'filter' => ['=ID' => $generationId],
		])->fetch();

		return $row ?: null;
	}

	private function decodeConnectionSnapshot(array $row): array
	{
		$snapshot = [];
		foreach (self::PROJECTED_FIELDS as $field)
		{
			$snapshot[$field] = SettingsWriteCoordinator::decodeStoredField(
				MailboxSourceGenerationTable::class,
				$field,
				$row[$field],
			);
		}

		return $snapshot;
	}

	private function compareAndSetPointer(Connection $connection, int $mailboxId, int $from, int $to): bool
	{
		$connection->queryExecute(sprintf(
			'UPDATE b_mail_mailbox SET ACTIVE_GENERATION_ID = %u WHERE ID = %u AND ACTIVE_GENERATION_ID = %u',
			$to,
			$mailboxId,
			$from,
		));

		return $connection->getAffectedRowsCount() === 1;
	}

	/**
	 * At most one generation of a mailbox is active, so every other active one is
	 * retained by the same statement that hands the mailbox over.
	 */
	private function archiveOtherActiveGenerations(Connection $connection, int $mailboxId, int $targetId): void
	{
		$connection->queryExecute(sprintf(
			"UPDATE %s SET STATUS = '%s', DATE_ARCHIVED = %s WHERE MAILBOX_ID = %u AND ID <> %u AND STATUS = '%s'",
			MailboxSourceGenerationTable::getTableName(),
			MailboxSourceGenerationTable::STATUS_ARCHIVED,
			$connection->getSqlHelper()->convertToDbDateTime(new DateTime()),
			$mailboxId,
			$targetId,
			MailboxSourceGenerationTable::STATUS_ACTIVE,
		));
	}

	private function activateTarget(Connection $connection, int $targetId, int $revision): bool
	{
		$connection->queryExecute(sprintf(
			"UPDATE %s SET STATUS = '%s', DATE_ACTIVATED = %s, REVISION = REVISION + 1"
			. " WHERE ID = %u AND STATUS = '%s' AND REVISION = %u",
			MailboxSourceGenerationTable::getTableName(),
			MailboxSourceGenerationTable::STATUS_ACTIVE,
			$connection->getSqlHelper()->convertToDbDateTime(new DateTime()),
			$targetId,
			MailboxSourceGenerationTable::STATUS_PREPARING,
			$revision,
		));

		return $connection->getAffectedRowsCount() === 1;
	}

	private function invalidateGenerationCaches(int $mailboxId): void
	{
		GenerationScope::invalidateActivePointerCache($mailboxId);
		MailboxDirectory::invalidateCache($mailboxId);
		/*
			The cached date of the earliest letter of a folder was counted over the letters of the source
			just retained, and the folders of the new source start with no letters at all. Dropping it here
			and not at the release of the feature: a mailbox that changes its source later needs the same.
		*/
		Helper\Message\MessageInternalDateHandler::clearStartInternalDate($mailboxId);
	}

	/**
	 * The same mailbox sync lock the synchronization takes, so the switch never lands
	 * beside a working sync of the generation it retains.
	 *
	 * A migration takes it earlier - before the final synchronization of the retained
	 * source - and holds it over the delta and the switch, so the primitive is available to
	 * it and the lease it got is what it hands to {@see switch()}.
	 *
	 * @return array|null ['acquired' => int, 'previous' => int] or null when the lock is busy.
	 */
	public function lockMailbox(int $mailboxId): ?array
	{
		$connection = Application::getConnection();
		$timeout = Helper\Mailbox::getTimeout();
		$now = time();

		$previous = (int)$connection->queryScalar(sprintf(
			'SELECT SYNC_LOCK FROM b_mail_mailbox WHERE ID = %u',
			$mailboxId,
		));

		if ($now - $previous < $timeout)
		{
			return null;
		}

		$connection->queryExecute(sprintf(
			'UPDATE b_mail_mailbox SET SYNC_LOCK = %u WHERE ID = %u AND (SYNC_LOCK IS NULL OR SYNC_LOCK < %u)',
			$now,
			$mailboxId,
			$now - $timeout,
		));

		return $connection->getAffectedRowsCount() > 0
			? ['acquired' => $now, 'previous' => $previous]
			: null;
	}

	/**
	 * Refreshes a lease immediately before the short switch transaction. The final
	 * synchronization and its delta may legitimately outlive the original lease; the
	 * compare-and-set decides whether this hand-over or a regular synchronization owns
	 * the mailbox now.
	 *
	 * @param array $lock ['acquired' => int, 'previous' => int]
	 * @return array|null The refreshed lease or null when another process took the mailbox.
	 */
	private function renewMailbox(int $mailboxId, array $lock): ?array
	{
		$connection = Application::getConnection();
		$acquired = (int)$lock['acquired'];
		$now = time();

		if ($acquired === $now && $this->holdsMailbox($mailboxId, $lock))
		{
			return $lock;
		}

		$connection->queryExecute(sprintf(
			'UPDATE b_mail_mailbox SET SYNC_LOCK = %u WHERE ID = %u AND SYNC_LOCK = %u',
			$now,
			$mailboxId,
			$acquired,
		));

		if ($connection->getAffectedRowsCount() !== 1)
		{
			return null;
		}

		$lock['acquired'] = $now;

		return $lock;
	}

	/**
	 * Whether the lease of the lock is still the one the mailbox holds.
	 */
	private function holdsMailbox(int $mailboxId, array $lock): bool
	{
		$row = Application::getConnection()->query(sprintf(
			'SELECT SYNC_LOCK FROM b_mail_mailbox WHERE ID = %u FOR UPDATE',
			$mailboxId,
		))->fetch();

		return $row !== false && (int)$row['SYNC_LOCK'] === (int)$lock['acquired'];
	}

	public function unlockMailbox(int $mailboxId, array $lock): void
	{
		Application::getConnection()->queryExecute(sprintf(
			'UPDATE b_mail_mailbox SET SYNC_LOCK = %d WHERE ID = %u AND SYNC_LOCK = %u',
			$lock['previous'],
			$mailboxId,
			$lock['acquired'],
		));
	}
}
