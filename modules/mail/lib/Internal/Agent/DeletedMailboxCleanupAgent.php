<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Agent;

use Bitrix\Mail\Internals\MailboxSourceGenerationTable;
use Bitrix\Mail\Internals\MessageConversationActivityTable;
use Bitrix\Mail\Internals\MessageConversationMemberTable;
use Bitrix\Mail\Internals\MessageConversationTable;
use Bitrix\Mail\Internals\MigrationManualControlTable;
use Bitrix\Mail\Internals\MigrationOperationTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;

/**
 * Collects the rows of mailboxes that are not in the database anymore.
 *
 * The deletion of a mailbox is the only path that takes those rows away, it runs once, and it
 * cannot be repeated: by the time anyone could notice that it left something behind, the mailbox
 * row itself is gone and with it every trace of what was unfinished. So the leftovers are found
 * by a condition that needs no bookkeeping of its own - the owner of the row is not there - and
 * every statement below carries that condition itself. Two things follow, and they are the reason
 * this agent exists at all: the sweep is safe to repeat any number of times, and it cannot touch
 * a mailbox that is alive, whatever the walk over the ids believes about it.
 *
 * The cursor of the walk travels in the name of the agent, the way the other cursor carrying
 * agents of the module do it. A lap that reaches the last id starts over: an id already passed
 * holds rows again only if a mailbox of that id is deleted, and the next lap meets it.
 *
 * The ids of a lap are the ids the swept tables hold rows of, and almost every one of them
 * belongs to a mailbox that is alive - an installation that never lost a deletion halfway has
 * nothing else. So the ids of a run are collected first and asked about their owners in one
 * statement, and only the ones the database answers for as ownerless are swept. Without that
 * step a run of a healthy installation spent a listing of letters and a series of deletes that
 * were bound to match nothing on every living mailbox it walked past.
 */
final class DeletedMailboxCleanupAgent
{
	/** LIKE mask: the exact name changes as the cursor of the walk moves */
	public const NAME_MASK = self::class . '::run(%';

	public const INTERVAL_SECONDS = 3600;

	/** Mailbox ids one run looks at: one statement each, and a sweep only for the ownerless ones */
	private const IDS_PER_RUN = 100;

	/** Letters of one deleted mailbox per run: each of them goes with its attachments and files */
	private const LETTERS_PER_MAILBOX = 100;

	/** Rows of one auxiliary table deleted for a mailbox in one run */
	private const ROWS_PER_TABLE = 100;

	private const TIME_LIMIT_SECONDS = 5;

	private const LETTERS_TABLE = 'b_mail_message';

	public static function getName(int $lastSweptMailboxId = 0): string
	{
		return sprintf('%s::run(%u);', self::class, max(0, $lastSweptMailboxId));
	}

	/**
	 * Makes sure the sweep is scheduled. Called by a deletion of a mailbox that could not finish
	 * its own cleanup: what it left behind has no other collector, and the deletion cannot be
	 * repeated.
	 */
	public static function ensureScheduled(): void
	{
		try
		{
			if (\CAgent::getList([], ['NAME' => self::NAME_MASK])->fetch())
			{
				return;
			}

			\CAgent::addAgent(self::getName(), 'mail', 'N', self::INTERVAL_SECONDS);
		}
		catch (\Throwable $exception)
		{
			self::log('the agent could not be scheduled', $exception->getMessage());
		}
	}

	/**
	 * @param int $lastSweptMailboxId The id the previous run walked up to.
	 * @return string The call of the next run, its cursor included.
	 */
	public static function run(int $lastSweptMailboxId = 0): string
	{
		$connection = Application::getConnection();
		$deadline = microtime(true) + self::TIME_LIMIT_SECONDS;
		$tables = self::sweptTablesOf($connection);

		[$candidates, $cursor, $lapIsOver] = self::collectIds(
			$connection,
			$tables,
			max(0, $lastSweptMailboxId),
			$deadline,
		);

		foreach (self::theOnesWithoutAnOwner($connection, $candidates) as $mailboxId)
		{
			if (!self::sweep($connection, $tables, $mailboxId, $deadline))
			{
				// More of this mailbox is left than one run takes: the cursor stays before it
				return self::getName($mailboxId - 1);
			}
		}

		// A lap that reached the last id there is opens a new one
		return $lapIsOver ? self::getName() : self::getName($cursor);
	}

	/**
	 * The ids of this run: the ones the swept tables hold rows of, above the cursor of the walk.
	 * Whether a mailbox of such an id exists is not asked here - one statement answers that for
	 * the whole batch {@see theOnesWithoutAnOwner()}.
	 *
	 * @param string[] $tables
	 * @return array{0: int[], 1: int, 2: bool} The ids ascending, the cursor of the walk and
	 *         whether it reached the last id there is.
	 */
	private static function collectIds(
		Connection $connection,
		array $tables,
		int $after,
		float $deadline,
	): array
	{
		$ids = [];
		$cursor = $after;

		while (count($ids) < self::IDS_PER_RUN && microtime(true) < $deadline)
		{
			$next = self::nextMailboxId($connection, $tables, $cursor);

			if ($next === null)
			{
				return [$ids, $cursor, true];
			}

			$ids[] = $next;
			$cursor = $next;
		}

		return [$ids, $cursor, false];
	}

	/**
	 * @param string[] $tables
	 * @return int|null The next id any of the swept tables holds a row of; null past the last one.
	 */
	private static function nextMailboxId(Connection $connection, array $tables, int $after): ?int
	{
		$minimums = [];

		foreach ([self::LETTERS_TABLE, ...$tables] as $table)
		{
			/*
				MAILBOX_ID leads an index of every one of these tables, so the minimum of a range
				of it is an index seek - the same cost on the largest table of the module as on the
				smallest. All of them go in one statement: a step of the walk over a living mailbox
				is then one round trip and not one per table.
			*/
			$minimums[] = sprintf(
				'SELECT MIN(MAILBOX_ID) AS %s FROM %s WHERE MAILBOX_ID > %u',
				self::idColumn($connection),
				$table,
				$after,
			);
		}

		try
		{
			$next = (int)$connection->queryScalar(sprintf(
				'SELECT MIN(%s) FROM (%s) candidates',
				self::idColumn($connection),
				implode(' UNION ALL ', $minimums),
			));
		}
		catch (\Throwable $exception)
		{
			self::log('the walk over the mailbox ids', $exception->getMessage());

			return null;
		}

		return $next > 0 ? $next : null;
	}

	/**
	 * Which of the ids belong to no mailbox, decided by the database and in one statement. The
	 * question is the same one every statement of the sweep carries itself, so this answer is a
	 * matter of cost alone: an id it lets through cannot make a living mailbox lose a row.
	 *
	 * @param int[] $candidates
	 * @return int[] Ascending.
	 */
	private static function theOnesWithoutAnOwner(Connection $connection, array $candidates): array
	{
		if ($candidates === [])
		{
			return [];
		}

		$column = self::idColumn($connection);
		$named = [];

		foreach ($candidates as $candidate)
		{
			$named[] = sprintf('SELECT %u AS %s', $candidate, $column);
		}

		try
		{
			$rows = $connection->query(sprintf(
				'SELECT candidates.%1$s FROM (%2$s) candidates'
				. ' WHERE NOT EXISTS (SELECT 1 FROM b_mail_mailbox WHERE b_mail_mailbox.ID = candidates.%1$s)'
				. ' ORDER BY candidates.%1$s',
				$column,
				implode(' UNION ALL ', $named),
			))->fetchAll();
		}
		catch (\Throwable $exception)
		{
			self::log('the owners of the mailbox ids', $exception->getMessage());

			return [];
		}

		return array_map(static fn (array $row): int => (int)reset($row), $rows);
	}

	/**
	 * Everything of one mailbox id, and nothing at all when a mailbox of that id exists: the
	 * condition is part of every statement here, so a mailbox that is alive keeps all of its
	 * rows even if the walk arrives at its id.
	 *
	 * @param string[] $tables
	 * @return bool Whether the walk may move past this id. A failure does not hold it back - the
	 *         next lap comes back to the same id anyway, and a row nothing can delete would
	 *         otherwise stop the sweep of every id after it.
	 */
	private static function sweep(Connection $connection, array $tables, int $mailboxId, float $deadline): bool
	{
		$mayMoveOn = self::sweepLetters($connection, $mailboxId, $deadline);

		foreach ($tables as $table)
		{
			if (!self::sweepTable($connection, $table, $mailboxId))
			{
				$mayMoveOn = false;
			}
		}

		return $mayMoveOn;
	}

	/**
	 * Deletes one bounded page of an auxiliary table.
	 *
	 * @return bool Whether this table is done with for now.
	 */
	private static function sweepTable(Connection $connection, string $table, int $mailboxId): bool
	{
		try
		{
			$keyColumns = self::keyColumnsOf($table);
			$columns = implode(', ', $keyColumns);

			$keys = $connection->query(sprintf(
				'SELECT %2$s FROM %3$s WHERE MAILBOX_ID = %1$u'
				. ' AND NOT EXISTS (SELECT 1 FROM b_mail_mailbox WHERE b_mail_mailbox.ID = %1$u)'
				. ' LIMIT %4$u',
				$mailboxId,
				$columns,
				$table,
				self::ROWS_PER_TABLE,
			))->fetchAll();

			if ($keys === [])
			{
				return true;
			}

			$connection->queryExecute(sprintf(
				'DELETE FROM %2$s WHERE MAILBOX_ID = %1$u'
				. ' AND NOT EXISTS (SELECT 1 FROM b_mail_mailbox WHERE b_mail_mailbox.ID = %1$u)'
				. ' AND (%3$s)',
				$mailboxId,
				$table,
				self::keyCondition($connection, $keyColumns, $keys),
			));
		}
		catch (\Throwable $exception)
		{
			self::log(sprintf('%s of the mailbox %u', $table, $mailboxId), $exception->getMessage());

			return true;
		}

		return count($keys) < self::ROWS_PER_TABLE;
	}

	/**
	 * @param string[] $keyColumns
	 * @param array<int, array<string, mixed>> $keys
	 */
	private static function keyCondition(Connection $connection, array $keyColumns, array $keys): string
	{
		$sqlHelper = $connection->getSqlHelper();
		$rows = [];

		foreach ($keys as $key)
		{
			$parts = [];

			foreach ($keyColumns as $column)
			{
				$parts[] = sprintf("%s = '%s'", $column, $sqlHelper->forSql((string)$key[$column]));
			}

			$rows[] = '(' . implode(' AND ', $parts) . ')';
		}

		return implode(' OR ', $rows);
	}

	/**
	 * @return bool Whether the letters of this mailbox are done with for now.
	 */
	private static function sweepLetters(Connection $connection, int $mailboxId, float $deadline): bool
	{
		try
		{
			$letters = $connection->query(sprintf(
				'SELECT ID FROM %3$s WHERE MAILBOX_ID = %1$u'
				. ' AND NOT EXISTS (SELECT 1 FROM b_mail_mailbox WHERE b_mail_mailbox.ID = %1$u)'
				. ' ORDER BY ID LIMIT %2$u',
				$mailboxId,
				self::LETTERS_PER_MAILBOX,
				self::LETTERS_TABLE,
			))->fetchAll();
		}
		catch (\Throwable $exception)
		{
			self::log(sprintf('the letters of the mailbox %u', $mailboxId), $exception->getMessage());

			return true;
		}

		foreach ($letters as $letter)
		{
			try
			{
				// Takes the attachments, the files and the placements of the letter with it
				\CMailMessage::Delete((int)$letter['ID'], $mailboxId);
			}
			catch (\Throwable $exception)
			{
				self::log(sprintf('the letter %u', (int)$letter['ID']), $exception->getMessage());
			}

			if (microtime(true) >= $deadline)
			{
				return false;
			}
		}

		return count($letters) < self::LETTERS_PER_MAILBOX;
	}

	/**
	 * Tables holding rows of one mailbox and nothing else, every one of them addressed by
	 * MAILBOX_ID alone and taken by a single statement. Source-generation and conversation-projection
	 * tables are here because neither has a foreign key that can remove its rows with the mailbox.
	 * The letters are not: they are deleted one by one, because a letter owns rows of its own and files
	 * outside the database.
	 *
	 * @return string[]
	 */
	private static function sweptTables(): array
	{
		return [
			'b_mail_message_uid',
			'b_mail_message_upload_queue',
			'b_mail_message_delete_queue',
			'b_mail_mailbox_dir',
			'b_mail_entity_options',
			'b_mail_entity_data',
			'b_mail_message_fingerprint',
			'b_mail_source_generation_match',
			'b_mail_source_generation_tail_mark',
			MailboxSourceGenerationTable::getTableName(),
			MigrationOperationTable::getTableName(),
			MigrationManualControlTable::getTableName(),
			MessageConversationActivityTable::getTableName(),
			MessageConversationMemberTable::getTableName(),
			MessageConversationTable::getTableName(),
		];
	}

	/** @return string[] */
	private static function keyColumnsOf(string $table): array
	{
		return match ($table)
		{
			'b_mail_message_uid', 'b_mail_message_upload_queue' => ['ID'],
			'b_mail_message_delete_queue' => ['PK'],
			'b_mail_mailbox_dir',
			'b_mail_message_fingerprint',
			'b_mail_source_generation_match',
			'b_mail_source_generation_tail_mark',
			'b_mail_mailbox_source_generation',
			'b_mail_migration_operation',
			'b_mail_migration_manual_control',
			'b_mail_message_conversation' => ['ID'],
			'b_mail_message_conversation_member' => ['MESSAGE_ID'],
			'b_mail_message_conversation_activity' => ['CONVERSATION_ID', 'SCOPE_KEY'],
			'b_mail_entity_options', 'b_mail_entity_data' => ['ENTITY_TYPE', 'ENTITY_ID', 'PROPERTY_NAME'],
		};
	}

	/**
	 * The swept tables this installation really has. Asked once per run, because the statements of
	 * a run name several tables at a time now: the files of an update reach an installation before
	 * its database, and one table that is not there yet would otherwise take a whole statement -
	 * and with it the walk over the ids - down with itself.
	 *
	 * @return string[]
	 */
	private static function sweptTablesOf(Connection $connection): array
	{
		$present = [];

		foreach (self::sweptTables() as $table)
		{
			try
			{
				if ($connection->isTableExists($table))
				{
					$present[] = $table;
				}
			}
			catch (\Throwable $exception)
			{
				self::log(sprintf('the presence of %s', $table), $exception->getMessage());
			}
		}

		return $present;
	}

	/** The name the statements below carry an id under, spelled the way this database spells it */
	private static function idColumn(Connection $connection): string
	{
		return $connection->getSqlHelper()->quote('ID');
	}

	private static function log(string $what, string $error): void
	{
		AddMessage2Log(
			sprintf('The cleanup after deleted mailboxes failed at %s: %s', $what, $error),
			'mail',
			2,
			false,
		);
	}
}
