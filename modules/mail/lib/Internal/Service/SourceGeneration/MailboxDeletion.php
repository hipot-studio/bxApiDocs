<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Internal\Agent\DeletedMailboxCleanupAgent;
use Bitrix\Mail\Internals\MailEntityDataTable;
use Bitrix\Mail\Internals\MailEntityOptionsTable;
use Bitrix\Mail\Internals\MigrationManualControlTable;
use Bitrix\Mail\MailMessageTable;
use Bitrix\Main\Application;

/**
 * What the deletion of a mailbox has to settle with the source generation operations of it.
 *
 * A pass of an operation writes physical rows and logical letters of the mailbox all the way
 * through, and it is bounded by a budget and not by a moment: it walks the folders of the new
 * source and may be minutes away from its end. So the deletion can neither simply outwait it
 * nor give up on it - a mailbox the user asked to delete must not stay half deleted. It asks
 * the operation to stop, and it lets the last of its own cleanups wait for the answer.
 */
final class MailboxDeletion
{
	/**
	 * Takes the mailbox away from its operations, asking the one that holds it to stop.
	 *
	 * The stop is asked by removing the generation bookkeeping of the mailbox: a pass makes sure
	 * before every folder and every batch of letters that the generation it imports into is still
	 * there, and one whose generation is gone keeps its cursors and returns without writing
	 * anything more. That is the whole request - it needs no marker of its own, a deletion that
	 * fails halfway leaves none behind, and the rows it removes are rows the deletion takes anyway.
	 *
	 * @param int $waitSeconds How long the pass that was asked to stop is given to notice.
	 * @return bool Whether the caller holds the lock now - and therefore has to release it.
	 */
	public static function takeMailboxOver(int $mailboxId, int $waitSeconds): bool
	{
		if (OperationLock::acquire($mailboxId))
		{
			return true;
		}

		BackfillService::onMailboxDeleted($mailboxId);

		return OperationLock::acquire($mailboxId, $waitSeconds);
	}

	/**
	 * The physical data of the mailbox as a whole: the placements of every generation, the queues
	 * that address them and the bookkeeping of the generations themselves. Every statement addresses
	 * the mailbox and not a letter of it, so this is idempotent, and that is what lets the deletion
	 * repeat it instead of proving that nothing wrote in between.
	 *
	 * @return bool False when the placements could not be deleted: the deletion has to stop there,
	 *         because everything after it is written on the assumption that they are gone.
	 */
	public static function deletePhysicalData(int $mailboxId): bool
	{
		$connection = Application::getConnection();

		try
		{
			$connection->queryExecute(sprintf('DELETE FROM b_mail_message_uid WHERE MAILBOX_ID = %u', $mailboxId));
		}
		catch (\Throwable)
		{
			return false;
		}

		// The queues address placements of this mailbox and have nothing left to work on
		$connection->queryExecute(sprintf(
			'DELETE FROM b_mail_message_upload_queue WHERE MAILBOX_ID = %u',
			$mailboxId,
		));
		$connection->queryExecute(sprintf(
			'DELETE FROM b_mail_message_delete_queue WHERE MAILBOX_ID = %u',
			$mailboxId,
		));

		/*
			The answer is deliberately not the answer of this method: the deletion the user asked for
			runs on it, and bookkeeping rows that refused to go are no reason to leave the mailbox half
			deleted. The finishing cleanup asks the same question in a stage of its own and hands what
			stayed to the collector.
		*/
		BackfillService::onMailboxDeleted($mailboxId);

		return true;
	}

	/**
	 * The finishing cleanup of the deletion, run once the mailbox row itself is gone.
	 *
	 * Everything the deletion does before this runs while the mailbox is still in the database, so
	 * a pass that was asked to stop but had not noticed yet could write after any of it. Two things
	 * make this the last word about the mailbox. Nothing starts writing again: an operation
	 * resolves its mailbox before anything else, and a mailbox that is not there gets no generation
	 * at all, so no pass reaches an import anymore. And nothing is still writing: the pass that was
	 * asked to stop is waited for here, and it answers within a batch of letters.
	 *
	 * A wait that runs out sweeps anyway - a mailbox the user asked to delete must not stay half
	 * deleted. The stages of the sweep are independent of each other and every one of them stands
	 * on its own: a stage that fails does not cancel those after it, because the rows they are
	 * about have nothing to do with the rows it failed on. What any of them leaves behind is
	 * collected by {@see DeletedMailboxCleanupAgent}, and the sweep makes sure it is scheduled
	 * before it gives up: the deletion has already succeeded by then, so this is the only way
	 * back to those rows - there is no mailbox left to repeat the deletion of.
	 *
	 * @param bool $holdsTheLock Whether the caller already holds the lock of the operations.
	 */
	public static function sweepAfterTheMailboxRow(int $mailboxId, bool $holdsTheLock, int $waitSeconds): void
	{
		$taken = $holdsTheLock || OperationLock::acquire($mailboxId, $waitSeconds);

		try
		{
			$done = self::stage($mailboxId, 'the manual migration control', static function () use ($mailboxId): bool {
				$connection = Application::getConnection();
				if ($connection->isTableExists(MigrationManualControlTable::getTableName()))
				{
					MigrationManualControlTable::deleteList(['=MAILBOX_ID' => $mailboxId]);
				}

				return true;
			});

			$done = self::stage(
				$mailboxId,
				'the physical data',
				static fn (): bool => self::deletePhysicalData($mailboxId),
			) && $done;

			/*
				The bookkeeping of the generations has a stage of its own although the statement
				above takes it as well: that one gives up as soon as the placements refuse to go,
				and the credentials of the previous source are in these rows.
			*/
			$done = self::stage(
				$mailboxId,
				'the generations',
				static fn (): bool => BackfillService::onMailboxDeleted($mailboxId),
			) && $done;

			$done = self::stage($mailboxId, 'the folders', static function () use ($mailboxId): bool {
				Application::getConnection()->queryExecute(
					sprintf('DELETE FROM b_mail_mailbox_dir WHERE MAILBOX_ID = %u', $mailboxId),
				);

				return true;
			}) && $done;

			$done = self::stage($mailboxId, 'the entity options', static function () use ($mailboxId): bool {
				MailEntityOptionsTable::deleteList(['=MAILBOX_ID' => $mailboxId]);

				return true;
			}) && $done;

			$done = self::stage($mailboxId, 'the entity data', static function () use ($mailboxId): bool {
				MailEntityDataTable::deleteList(['=MAILBOX_ID' => $mailboxId]);

				return true;
			}) && $done;

			$done = self::stage(
				$mailboxId,
				'the letters',
				static fn (): bool => self::deleteLetters($mailboxId),
			) && $done;

			if (!$done)
			{
				DeletedMailboxCleanupAgent::ensureScheduled();
			}
		}
		finally
		{
			if ($taken && !$holdsTheLock)
			{
				OperationLock::release($mailboxId);
			}
		}
	}

	/**
	 * The logical letters of the mailbox, one by one and each on its own: a letter whose
	 * attachment or file cannot be deleted takes neither its neighbours nor the stages after
	 * it down with it.
	 *
	 * @return bool Whether every letter of the mailbox is gone.
	 */
	private static function deleteLetters(int $mailboxId): bool
	{
		$letters = MailMessageTable::getList([
			'select' => ['ID'],
			'filter' => ['=MAILBOX_ID' => $mailboxId],
		]);

		$swept = true;

		while ($letter = $letters->fetch())
		{
			$swept = self::stage(
				$mailboxId,
				sprintf('the letter %u', (int)$letter['ID']),
				static fn (): bool => (bool)\CMailMessage::Delete((int)$letter['ID'], $mailboxId),
			) && $swept;
		}

		return $swept;
	}

	/**
	 * @return bool Whether the stage did its work. A failure reaches the log and nothing else:
	 *         the deletion of the mailbox has already succeeded, and the stages the failing one
	 *         does not stop are the ones that keep the leftovers of it to a minimum.
	 */
	private static function stage(int $mailboxId, string $what, callable $stage): bool
	{
		try
		{
			if ($stage())
			{
				return true;
			}

			$reason = 'the statement did not go through';
		}
		catch (\Throwable $exception)
		{
			$reason = $exception->getMessage();
		}

		AddMessage2Log(
			sprintf(
				'The finishing cleanup of the deleted mailbox %u failed at %s: %s',
				$mailboxId,
				$what,
				$reason,
			),
			'mail',
			2,
			false,
		);

		return false;
	}
}
