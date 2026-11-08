<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Helper;
use Bitrix\Mail\Internals\MailboxSourceGenerationTable;
use Bitrix\Mail\Internals\MigrationOperationTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\Type\DateTime;

/**
 * Repeatable initializer of the first source generation (G1) of a mailbox.
 *
 * The single service behind both launch modes: the creation of a mailbox, which assigns the
 * generation at once {@see onMailboxAdded()}, and the first stage of a migration, which
 * assigns it to a mailbox that has none yet. Both use the same deterministic operation id, so
 * together with the unique index over (MAILBOX_ID, OPERATION_ID) a mailbox can never get two
 * G1 rows. No mode walks the existing mailboxes of the portal: a mailbox nobody migrates keeps
 * the implicit generation zero for good, and that is what the read paths served before the
 * feature.
 *
 * The walk of one mailbox is restartable at any point: every step filters by
 * GENERATION_ID = 0 and the finalization order (assign rows, then activate the
 * generation, then set the pointer) keeps the mailbox consistent for the sync
 * context resolver after a crash in between. Physical rows written with the
 * implicit generation 0 after the pointer flip - writers that do not stamp the
 * generation yet - are adopted by the next run over the same mailbox, and until
 * one comes they are read as rows of the active source anyway: the generation
 * zero belongs to every scope by construction {@see GenerationScope::active()}.
 *
 * A run reports how many physical rows it assigned, and the one caller that asks is a pass of a
 * migration {@see MigrationService::run()}: it passes the answer on as the progress of that pass,
 * which the retry policy of the agent decides by. That is what an unfinished initialization is told
 * from a moving one by - the remainder is never counted for it, so an unfinished pass that assigned
 * nothing is a mailbox that is not moving whatever is left of it.
 */
class BackfillService
{
	private readonly RolloutBrakeReader $rolloutBrakeReader;

	public const STATE_LEGACY = 'LEGACY';
	public const STATE_INITIALIZING_G1 = 'INITIALIZING_G1';
	public const STATE_G1_READY = 'G1_READY';

	/** Deterministic operation of the G1 backfill, one per mailbox by the unique index. */
	public const OPERATION_ID = MailboxSourceGenerationTable::OPERATION_G1_BACKFILL;

	public const UNLIMITED_ROW_BUDGET = 0;

	public const FEATURE_OPTION = 'source_generations_enabled';

	/**
	 * The emergency brake of the rollout: no mailbox starts or continues a migration
	 * while it is on. The mailboxes already switched keep working on their new source.
	 */
	public const MIGRATION_STOP_OPTION = 'source_generations_migration_stopped';

	/* The conditions of the rollout, as {@see findMigrationRefusal()} names the one that refused */
	public const REFUSAL_MIGRATION_STOPPED = 'MIGRATION_STOPPED';
	public const REFUSAL_FEATURE_DISABLED = 'FEATURE_DISABLED';

	/**
	 * How many rows one batch of the assignment reads and one statement of it then assigns. The
	 * value of the steppers of the core (AdminGridOption, AdminFilterOption, UserStepper), and a
	 * quarter of the portion the batch writers of the module carry: the length of a statement is
	 * what reaches the binary log and the replay on a replica, so this one stays deliberately low.
	 */
	protected const ASSIGN_BATCH_SIZE = 100;

	protected const IMAP_SERVER_TYPES = ['imap', 'controller', 'domain', 'crdomain'];

	public function __construct(?RolloutBrakeReader $rolloutBrakeReader = null)
	{
		$this->rolloutBrakeReader = $rolloutBrakeReader ?? new RolloutBrakeReader();
	}

	/** Physical tables that belong to a generation: table => the key column a batch is named by. */
	public const GENERATION_SCOPED_TABLES = [
		'b_mail_message_uid' => 'ID',
		'b_mail_mailbox_dir' => 'ID',
		'b_mail_message_upload_queue' => 'ID',
		'b_mail_message_delete_queue' => 'PK',
	];

	/**
	 * The state of the user operation before G2 exists: LEGACY -> INITIALIZING_G1 -> G1_READY.
	 */
	public function getState(int $mailboxId): string
	{
		$mailbox = $this->fetchMailbox($mailboxId);
		if ($mailbox === null || !$this->isImapFamily($mailbox))
		{
			return self::STATE_LEGACY;
		}

		if ($this->fetchActivePointer($mailboxId) > 0)
		{
			return self::STATE_G1_READY;
		}

		return $this->findBackfillGeneration($mailboxId) === null
			? self::STATE_LEGACY
			: self::STATE_INITIALIZING_G1
		;
	}

	/**
	 * Runs the repeatable G1 initialization of one mailbox and returns the resulting state.
	 *
	 * A positive row budget limits how many physical rows one call may assign; an
	 * exhausted budget returns INITIALIZING_G1 and the next call continues the walk.
	 * A deadline bounds the same walk by the wall clock, and it holds for the final
	 * sweep under the lock as well: what a batch costs depends on the load of the
	 * database rather than on how many rows are left, so a budget of rows alone does
	 * not bound a pass.
	 * The finalization (the last sweep, the activation and the pointer flip) runs
	 * under the mailbox sync lock, so it never races a running synchronization; a
	 * busy lock also returns INITIALIZING_G1 to be retried later.
	 *
	 * @param int|null $assignedRows Receives how many physical rows this call assigned to the
	 *                               generation. An unfinished call that assigned none is a
	 *                               mailbox that is not moving, whatever is left over.
	 * @param int|null $deadline The wall clock this call may not walk past. Null for a caller
	 *                           that shares its pass with nobody: the creation of a mailbox and
	 *                           the manual run over a single mailbox.
	 */
	public function ensureFirstGeneration(
		int $mailboxId,
		int $rowBudget = self::UNLIMITED_ROW_BUDGET,
		?int &$assignedRows = null,
		?int $deadline = null,
	): string
	{
		$assignedRows = 0;
		$mailbox = $this->fetchMailbox($mailboxId);
		if ($mailbox === null || !$this->isImapFamily($mailbox))
		{
			return self::STATE_LEGACY;
		}

		$pointer = $this->fetchActivePointer($mailboxId);
		if ($pointer > 0)
		{
			/*
				Adopt rows written with the implicit generation 0 after the flip. An unfinished sweep is
				answered as such and not as readiness: the active scope covers the implicit zero, so a row
				left with it would pass for a row of the next source after the switch - and a command over
				it would be admitted and sent to that source with the number of the previous one.
			*/
			if ($this->assignPhysicalRows($mailboxId, $pointer, $rowBudget, $assignedRows, $deadline) === false)
			{
				return self::STATE_INITIALIZING_G1;
			}

			return self::STATE_G1_READY;
		}

		$generation = $this->findBackfillGeneration($mailboxId) ?? $this->createBackfillGeneration($mailbox);
		$generationId = (int)$generation['ID'];

		if ($generation['STATUS'] === MailboxSourceGenerationTable::STATUS_ARCHIVED)
		{
			throw new InvalidOperationException(sprintf(
				'The G1 backfill generation %u of the mailbox %u is archived',
				$generationId,
				$mailboxId,
			));
		}

		if ($this->assignPhysicalRows($mailboxId, $generationId, $rowBudget, $assignedRows, $deadline) === false)
		{
			return self::STATE_INITIALIZING_G1;
		}

		$lock = $this->lockMailbox($mailbox);
		if ($lock === null)
		{
			return self::STATE_INITIALIZING_G1;
		}

		try
		{
			/*
				Nothing can start a sync while the lock is held, so this sweep is the final one and
				spends no budget of rows. The clock it shares with the rest of the pass still holds:
				an unfinished sweep leaves the generation inactive and the pointer where it was,
				because a row left with the implicit zero would pass for a row of the new source
				after the flip - and a command over it would be sent there with the wrong number.
			*/
			if (
				$this->assignPhysicalRows($mailboxId, $generationId, self::UNLIMITED_ROW_BUDGET, $assignedRows, $deadline)
				=== false
			)
			{
				return self::STATE_INITIALIZING_G1;
			}

			// The resolver requires an ACTIVE generation, so the status goes before the pointer
			if ($generation['STATUS'] === MailboxSourceGenerationTable::STATUS_PREPARING)
			{
				$activated = MailboxSourceGenerationTable::update($generationId, [
					'STATUS' => MailboxSourceGenerationTable::STATUS_ACTIVE,
					'DATE_ACTIVATED' => new DateTime(),
				]);
				if (!$activated->isSuccess())
				{
					throw new InvalidOperationException(sprintf(
						'Unable to activate the G1 generation %u of the mailbox %u: %s',
						$generationId,
						$mailboxId,
						MigrationMetrics::withoutSecretsOf($mailbox, implode('; ', $activated->getErrorMessages())),
					));
				}
			}

			$this->setActivePointer($mailboxId, $generationId);
		}
		finally
		{
			$this->unlockMailbox($mailboxId, $lock);
		}

		return self::STATE_G1_READY;
	}

	/**
	 * Physical rows of the mailbox not assigned to any generation yet.
	 *
	 * No path of the module counts them: the walk stops on a budget or a clock and never on a
	 * remainder, and a pass that assigned nothing is how a mailbox that is not moving is told from one
	 * that is. Kept for the tests, which read the remainder to check that a walk continued where it
	 * left off - and as the guard the criterion of a count per pass is closed by.
	 */
	public function countUnassignedRows(int $mailboxId): int
	{
		$connection = Application::getConnection();

		$count = 0;
		foreach (array_keys(self::GENERATION_SCOPED_TABLES) as $table)
		{
			$count += (int)$connection->queryScalar(sprintf(
				'SELECT COUNT(*) FROM %s WHERE MAILBOX_ID = %u AND GENERATION_ID = 0',
				$table,
				$mailboxId,
			));
		}

		return $count;
	}

	/**
	 * The feature of the installation, without asking about a mailbox.
	 */
	public function isFeatureEnabled(): bool
	{
		return Option::get('mail', self::FEATURE_OPTION, 'N') === 'Y';
	}

	/**
	 * The emergency brake: new migrations and the synchronization of prepared generations
	 * stop. It says nothing about the mailboxes that have already switched - their new
	 * source keeps serving them, and no legacy path returns to the rows of every generation.
	 */
	public function isMigrationStopped(): bool
	{
		return Option::get('mail', self::MIGRATION_STOP_OPTION, 'N') === 'Y';
	}

	/**
	 * The full admission of a mailbox to a migration of its physical source: the feature is on
	 * and the emergency brake is off. Nothing else - and the readiness of the first generation
	 * least of all, because the migration builds it by its own first stage. That requirement was
	 * written for an assignment made in advance, which no longer happens, and without it the
	 * requirement turned the offer of the move into a dead end.
	 *
	 * Both conditions are options of the portal, so the admission asks the database nothing at
	 * all, not even once per mailbox of a list. The mailbox is still named by the parameter: the
	 * question is about one mailbox, and the next condition of the rollout - a cohort, a type -
	 * will depend on it again.
	 *
	 * Two things it deliberately leaves to others:
	 *
	 * - whether the mailbox is of a type that can move at all. One outside the IMAP family is
	 *   admitted here and stopped by the migration itself, which has nowhere to take it; the
	 *   screen offering the move filters the type by {@see supportsServerType()} instead, free of
	 *   charge, because it holds the row of the mailbox already;
	 * - the same rule in the shape an operation needs. The conditions and their order are this
	 *   one answer {@see findMigrationRefusal()}, and
	 *   {@see MigrationService::findRolloutRefusal()} only gives the refusal an error code.
	 */
	public function isMigrationAllowed(int $mailboxId): bool
	{
		return $this->findMigrationRefusal($mailboxId) === null;
	}

	/**
	 * Which condition of the rollout refuses the mailbox, if any does. The set of the conditions
	 * and the order they are asked in live here alone: an operation needs the same rule with an
	 * error code of its own, and a second copy of the rule is how the two answers drift apart.
	 *
	 * Costs no query, as {@see isMigrationAllowed()} above explains, and takes the mailbox for
	 * the same reason it does.
	 *
	 * @return string|null One of the REFUSAL_* names, null for a mailbox the rollout admits.
	 */
	public function findMigrationRefusal(int $mailboxId): ?string
	{
		return $this->rolloutBrakeReader->findRefusalNow();
	}

	/**
	 * A new mailbox creates its G1 right away. Never breaks the mailbox creation: on any failure
	 * (e.g. the schema of an update window) the mailbox is left without a generation, and the
	 * first stage of a migration is what picks it up - the only thing that does. There is no
	 * background walk behind this call anymore, so a mailbox nothing ever migrates keeps the
	 * implicit generation zero, which every read path serves as it did before the feature.
	 *
	 * Editing a mailbox assigns nothing at all: the creation is the one hook of this service on
	 * that path {@see \CAllMailBox::Add()}, and {@see \CAllMailBox::Update()} calls nothing of the
	 * kind. A mailbox whose type is changed to an IMAP one by an edit gets its generation when it
	 * goes into a migration and not a moment earlier.
	 */
	public static function onMailboxAdded(int $mailboxId): void
	{
		try
		{
			(new static())->initializeUnderOperationLock($mailboxId);
		}
		catch (\Throwable)
		{
		}
	}

	/**
	 * The first generation of the mailbox, taken under the lock of its operations - the one a
	 * migration and the deletion of the mailbox take as well. This call creates a generation row and
	 * assigns physical rows to it, so one running beside a deletion leaves both behind with no owner.
	 *
	 * For a caller that already holds that lock - a migration pass initializing the mailbox it works
	 * on - {@see ensureFirstGeneration()} is the entry: the lock is not taken twice, because a nested
	 * release is not the same thing on every database engine.
	 *
	 * The lock is taken without waiting: a busy mailbox is answered with "still initializing", which
	 * every caller of this path already retries later.
	 *
	 * Neither a budget of rows nor a clock, unlike the entry above: the one caller of this one is the
	 * creation of a mailbox, which shares its pass with nobody and hands over a mailbox that has no
	 * physical rows at all - the assignment finds nothing to walk {@see onMailboxAdded()}.
	 */
	public function initializeUnderOperationLock(int $mailboxId): string
	{
		if (!OperationLock::acquire($mailboxId))
		{
			return self::STATE_INITIALIZING_G1;
		}

		try
		{
			return $this->ensureFirstGeneration($mailboxId);
		}
		finally
		{
			OperationLock::release($mailboxId);
		}
	}

	/**
	 * Drops the generation bookkeeping of a deleted mailbox, including the orphan
	 * G1 of a rolled back connect attempt.
	 *
	 * The credentials of the snapshot go before the row itself: the deletion of the mailbox
	 * is the only occasion to remove them, there is no second pass over a mailbox that is
	 * already gone, so a row that survives a failure here must at least carry no secrets.
	 *
	 * A failure never reaches the caller as an exception: a half deleted mailbox is worse than
	 * an orphan row, and this runs on the low level path of the deletion. It is answered instead
	 * of being swallowed - the finishing cleanup schedules the collector of the leftovers by that
	 * answer, and nothing else can tell that these rows stayed.
	 *
	 * @return bool Whether the bookkeeping of the mailbox is gone. An installation whose database
	 *         has not caught up with the files has none to remove, which is not a failure.
	 */
	public static function onMailboxDeleted(int $mailboxId): bool
	{
		try
		{
			// The files of an update reach the installation before its database
			if (!GenerationScope::isSchemaInstalled())
			{
				return true;
			}

			$connection = Application::getConnection();

			/*
				The pointer goes before the rows it names. A deletion of a mailbox destroys the physical
				data first and by design - a letter without a placement is a state the module collects, a
				placement without a letter is not - and every step after that can still fail. A mailbox
				left pointing at a generation that no longer exists resolves to nothing at all: the
				context of its source cannot be built, so every synchronization and every write path of
				that mailbox fails with an internal error instead of an answer. With the pointer cleared
				it degrades to the behaviour it had before the feature, and a repeated deletion finishes
				the job.
			*/
			$connection->queryExecute(sprintf(
				'UPDATE b_mail_mailbox SET ACTIVE_GENERATION_ID = 0 WHERE ID = %u',
				$mailboxId,
			));
			GenerationScope::invalidateActivePointerCache($mailboxId);

			$connection->queryExecute(sprintf(
				'UPDATE %s SET SERVER = NULL, LOGIN = NULL, PASSWORD = NULL WHERE MAILBOX_ID = %u',
				MailboxSourceGenerationTable::getTableName(),
				$mailboxId,
			));

			foreach ([
				MailboxSourceGenerationTable::getTableName(),
				'b_mail_message_fingerprint',
				'b_mail_source_generation_match',
				'b_mail_source_generation_tail_mark',
			] as $table)
			{
				$connection->queryExecute(sprintf('DELETE FROM %s WHERE MAILBOX_ID = %u', $table, $mailboxId));
			}

			if ($connection->isTableExists(MigrationOperationTable::getTableName()))
			{
				$connection->queryExecute(sprintf(
					'DELETE FROM %s WHERE MAILBOX_ID = %u',
					MigrationOperationTable::getTableName(),
					$mailboxId,
				));
			}

			return true;
		}
		catch (\Throwable $exception)
		{
			AddMessage2Log(
				sprintf(
					'BackfillService::onMailboxDeleted failed: mailbox=%u, error=%s',
					$mailboxId,
					$exception->getMessage(),
				),
				'mail',
				2,
				false
			);

			return false;
		}
	}

	protected function fetchMailbox(int $mailboxId): ?array
	{
		if ($mailboxId <= 0)
		{
			return null;
		}

		// Only the long existing fields: the ORM map of another release may not know the new ones
		$row = MailboxTable::getList([
			'select' => ['ID', 'SERVER_TYPE', 'SERVICE_ID', 'SERVER', 'PORT', 'USE_TLS', 'LOGIN', 'PASSWORD', 'SYNC_LOCK'],
			'filter' => ['=ID' => $mailboxId],
		])->fetch();

		return $row ?: null;
	}

	protected function isImapFamily(array $mailbox): bool
	{
		return self::supportsServerType((string)$mailbox['SERVER_TYPE']);
	}

	public static function supportsServerType(string $serverType): bool
	{
		return in_array(mb_strtolower($serverType), self::IMAP_SERVER_TYPES, true);
	}

	protected function fetchActivePointer(int $mailboxId): int
	{
		return (int)Application::getConnection()->queryScalar(sprintf(
			'SELECT ACTIVE_GENERATION_ID FROM b_mail_mailbox WHERE ID = %u',
			$mailboxId,
		));
	}

	protected function setActivePointer(int $mailboxId, int $generationId): void
	{
		Application::getConnection()->queryExecute(sprintf(
			'UPDATE b_mail_mailbox SET ACTIVE_GENERATION_ID = %u WHERE ID = %u',
			$generationId,
			$mailboxId,
		));

		// As every other writer of the pointer does: the scope of a read is derived from it
		GenerationScope::invalidateActivePointerCache($mailboxId);
	}

	protected function findBackfillGeneration(int $mailboxId): ?array
	{
		$row = MailboxSourceGenerationTable::getList([
			'select' => ['ID', 'STATUS'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'=OPERATION_ID' => self::OPERATION_ID,
			],
		])->fetch();

		return $row ?: null;
	}

	/**
	 * Creates the PREPARING G1 with a snapshot of the IMAP credentials of the mailbox.
	 * A concurrent creation loses on the unique (MAILBOX_ID, OPERATION_ID) index and
	 * rereads the winner.
	 */
	protected function createBackfillGeneration(array $mailbox): array
	{
		$mailboxId = (int)$mailbox['ID'];

		try
		{
			$result = MailboxSourceGenerationTable::add([
				'MAILBOX_ID' => $mailboxId,
				'OPERATION_ID' => self::OPERATION_ID,
				'STATUS' => MailboxSourceGenerationTable::STATUS_PREPARING,
				'REVISION' => 0,
				'SERVICE_ID' => (int)$mailbox['SERVICE_ID'],
				'SERVICE_NAME' => $this->resolveServiceName((int)$mailbox['SERVICE_ID']),
				'SERVER' => (string)$mailbox['SERVER'],
				'PORT' => (int)$mailbox['PORT'],
				'USE_TLS' => in_array($mailbox['USE_TLS'], ['Y', 'S'], true) ? $mailbox['USE_TLS'] : 'N',
				'LOGIN' => (string)$mailbox['LOGIN'],
				'PASSWORD' => (string)$mailbox['PASSWORD'],
				'OPTIONS' => [],
				'DATE_CREATE' => new DateTime(),
			]);
			if (!$result->isSuccess())
			{
				throw new InvalidOperationException(sprintf(
					'Unable to create the G1 backfill generation of the mailbox %u: %s',
					$mailboxId,
					MigrationMetrics::withoutSecretsOf($mailbox, implode('; ', $result->getErrorMessages())),
				));
			}

			return [
				'ID' => (int)$result->getId(),
				'STATUS' => MailboxSourceGenerationTable::STATUS_PREPARING,
			];
		}
		catch (SqlQueryException)
		{
			$existing = $this->findBackfillGeneration($mailboxId);
			if ($existing === null)
			{
				throw new InvalidOperationException(sprintf(
					'Unable to create or find the G1 backfill generation of the mailbox %u',
					$mailboxId,
				));
			}

			return $existing;
		}
	}

	protected function resolveServiceName(int $serviceId): ?string
	{
		if ($serviceId <= 0)
		{
			return null;
		}

		$service = \Bitrix\Mail\MailServicesTable::getByPrimary(
			$serviceId,
			['select' => ['ID', 'NAME']],
		)->fetch();

		return $service ? (string)$service['NAME'] : null;
	}

	/**
	 * Assigns unassigned physical rows of the mailbox to the generation: a batch of keys is read
	 * by the index leading with the mailbox and the generation, then one statement assigns the
	 * whole batch by naming those keys {@see ASSIGN_BATCH_SIZE}.
	 *
	 * The mailbox stays in the condition beside the keys: the primary key of the placements and
	 * of the upload queue is the pair of the row and the mailbox, and the row identifier alone is
	 * a hash of the coordinates of a letter, unique only within one mailbox. The identifiers are
	 * escaped rather than trusted - the POP3 collection writes the answer of a server into one of
	 * them {@see \CAllMailBox::GetMessage()}.
	 *
	 * The filter of the batch consumes itself, so the walk needs no cursor: an assigned row
	 * leaves the condition, the next batch of it is the next hundred rows, a repeated run over
	 * an assigned mailbox reads four empty batches and writes nothing, and an interrupted run
	 * resumes from the first row that is still unassigned. A cursor over the primary key would
	 * be worse than none here - no index of the four tables carries the key third, so it would
	 * either sort the rows of the mailbox or walk the whole table of every mailbox.
	 *
	 * The old uid row identifiers are never touched, only the GENERATION_ID column changes.
	 *
	 * @param int|null $assignedRows Grows by the rows this call assigned.
	 * @param int|null $deadline The wall clock the walk may not pass, null for no limit.
	 * @return int|false The budget left, or false when the walk stopped before the end.
	 */
	protected function assignPhysicalRows(
		int $mailboxId,
		int $generationId,
		int $rowBudget,
		?int &$assignedRows = null,
		?int $deadline = null,
	): int|false
	{
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();

		$unlimited = ($rowBudget === self::UNLIMITED_ROW_BUDGET);
		if (!$unlimited && $rowBudget <= 0)
		{
			return false;
		}

		foreach (self::GENERATION_SCOPED_TABLES as $table => $keyColumn)
		{
			while (true)
			{
				$batchSize = $unlimited
					? static::ASSIGN_BATCH_SIZE
					: min(static::ASSIGN_BATCH_SIZE, $rowBudget);

				$keys = $connection->query(sprintf(
					'SELECT %1$s FROM %2$s WHERE MAILBOX_ID = %3$u AND GENERATION_ID = 0 LIMIT %4$u',
					$keyColumn,
					$table,
					$mailboxId,
					$batchSize,
				))->fetchAll();

				if (empty($keys))
				{
					break;
				}

				$identifiers = array_map(
					static fn (array $row): string => "'" . $sqlHelper->forSql((string)$row[$keyColumn]) . "'",
					$keys,
				);

				// The generation of zero leaves alone a row a concurrent writer has taken out of this walk
				$connection->queryExecute(sprintf(
					'UPDATE %1$s SET GENERATION_ID = %2$u WHERE MAILBOX_ID = %3$u AND GENERATION_ID = 0 AND %4$s IN (%5$s)',
					$table,
					$generationId,
					$mailboxId,
					$keyColumn,
					implode(', ', $identifiers),
				));

				$assigned = $connection->getAffectedRowsCount();
				$assignedRows = ($assignedRows ?? 0) + $assigned;

				if (!$unlimited)
				{
					$rowBudget -= count($keys);
				}

				// A whole batch that moved nothing would be read again: it belongs to the next call
				if ($assigned === 0)
				{
					return false;
				}

				if ((!$unlimited && $rowBudget <= 0) || ($deadline !== null && time() >= $deadline))
				{
					return false;
				}

				// A batch shorter than the one asked for is the end of this table
				if (count($keys) < $batchSize)
				{
					break;
				}
			}
		}

		return $unlimited ? self::UNLIMITED_ROW_BUDGET : max(0, $rowBudget);
	}

	/**
	 * Takes the same mailbox sync lock the synchronization uses, so the finalization
	 * never runs beside a working sync.
	 *
	 * @return array|null ['acquired' => int, 'previous' => int] or null when the lock is busy.
	 */
	protected function lockMailbox(array $mailbox): ?array
	{
		$now = time();
		$previous = (int)$mailbox['SYNC_LOCK'];

		if ($now - $previous < Helper\Mailbox::getTimeout())
		{
			return null;
		}

		$connection = Application::getConnection();
		$connection->queryExecute(sprintf(
			'UPDATE b_mail_mailbox SET SYNC_LOCK = %u WHERE ID = %u AND (SYNC_LOCK IS NULL OR SYNC_LOCK < %u)',
			$now,
			(int)$mailbox['ID'],
			$now - Helper\Mailbox::getTimeout(),
		));

		return $connection->getAffectedRowsCount() > 0
			? ['acquired' => $now, 'previous' => $previous]
			: null
		;
	}

	protected function unlockMailbox(int $mailboxId, array $lock): void
	{
		Application::getConnection()->queryExecute(sprintf(
			'UPDATE b_mail_mailbox SET SYNC_LOCK = %d WHERE ID = %u AND SYNC_LOCK = %u',
			$lock['previous'],
			$mailboxId,
			$lock['acquired'],
		));
	}
}
