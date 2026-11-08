<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Visibility;

use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Internal\Service\Sync\Journal\JournalWriter;
use Bitrix\Disk\Internal\Service\Sync\Journal\TombstoneReason;
use Bitrix\Disk\Internal\Service\Sync\SyncFeatureOption;
use Bitrix\Disk\Internal\Service\Sync\SyncLimits;
use Bitrix\Disk\Internals\TrackedObjectTable;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Closure;
use Psr\Log\LoggerInterface;

/**
 * The end of visibility by interaction: a row of the set the user has not come back to for longer than
 * the published window leaves it, and the leaving is announced.
 *
 * Until this walk existed the set had neither a capacity nor a lifetime - a row was removed only
 * together with the object it named - so an object opened once stayed visible to that user for as long
 * as the portal lived. A window of time was chosen over a capacity per user because a capacity has no
 * publishable meaning: "the fifty most recent" is not something the capabilities of a portal can state,
 * while a deterministic term is.
 *
 * The window is counted off the freshness stamp of the row, which is personal to the user
 * ({@see \Bitrix\Disk\TrackedObjectManager::refresh()}). It has to be: were the stamp moved by whoever
 * touched the object last, one active reader would hold the object in the set of everybody who had ever
 * opened it, and nothing would ever age.
 *
 * Two rules govern what leaves and what is said about it.
 *
 * The record is published before the row is deleted, never after, and only a row whose record the
 * journal took is deleted at all. An interruption between the two leaves a tombstone about an object
 * still in the set, which costs the client one refetch and nothing else - it drops what it does not
 * hold. The opposite order, and a row taken out after a record that was refused, lose the visibility
 * silently, and a silent loss is the one failure this contour is built not to have. A row kept over a
 * refused record is met again by the next tick, which starts from the oldest rows.
 *
 * A row the user came back to while the portion was being published keeps its place, and the tombstone
 * already sent for it is taken back by a record of entering the set. Nothing would take it back
 * otherwise: a refresh of a row publishes nothing at all, so the client would go without the object
 * until the object itself changed.
 *
 * A tombstone goes out only when the interaction was the last source of visibility. The writer answers
 * that question itself ({@see JournalWriter::publishPersonalTombstone()}): a user who also sees the
 * object by the rights of Disk is told nothing, because for them one source of two disappeared and the
 * object stayed where it was. After the row is gone neither a link nor an attachment carries visibility
 * in this contour any more - both of them confirm a row of the set rather than stand on their own
 * ({@see AccessSourceConfirmer}) - so the rights are the only second source there can be.
 *
 * The walk is bounded twice over: a portion is read at a time and a tick lasts no longer than its
 * budget. On the largest portals the set is millions of rows, and the first run after the window is
 * introduced meets every one of them that has aged since the portal was installed.
 */
final class InteractionAger
{
	private const LOGGER_CHANNEL = 'sync';

	/**
	 * How many rows one portion carries.
	 *
	 * The bound is the cost of a row rather than the cost of a statement: the writer asks the rights of
	 * the object about one user per row, which is its contract and the reason the portion is counted in
	 * hundreds and not in thousands. What a portal turns when the aging is too slow is the interval of
	 * the agent, which costs nothing per row.
	 */
	private const PORTION = 200;

	/**
	 * How long one tick works before it leaves the rest to the next one.
	 *
	 * A tick has to end well inside the run of the agents of a portal, and the walk resumes from the
	 * oldest rows anyway, so stopping early costs nothing but time. It is the whole answer to "how long
	 * does this take on a portal of millions of rows": one tick, this long.
	 */
	private const TICK_BUDGET_SECONDS = 20;

	private SyncLimits $limits;
	private JournalWriter $journal;
	private SyncFeatureOption $feature;
	private LoggerInterface $logger;
	private int $portion;
	private Closure $now;

	/**
	 * @param (callable(): int)|null $now the clock the window is measured against, in unix time. The
	 *        budget of a tick is deliberately not read from it: a clock standing still - which is what a
	 *        test hands in - would turn the budget into a walk without an end.
	 */
	public function __construct(
		?SyncLimits $limits = null,
		?JournalWriter $journal = null,
		?SyncFeatureOption $feature = null,
		?LoggerInterface $logger = null,
		int $portion = self::PORTION,
		?callable $now = null,
	)
	{
		$this->limits = $limits ?? new SyncLimits();
		$this->journal = $journal ?? new JournalWriter();
		$this->feature = $feature ?? new SyncFeatureOption();
		$this->logger = $logger ?? LoggerFactory::create(self::LOGGER_CHANNEL, feature: 'interaction aging');
		$this->portion = max(1, $portion);
		$this->now = $now === null ? static fn (): int => time() : Closure::fromCallable($now);
	}

	/**
	 * One tick of the walk: the oldest rows that fell out of the window, portion by portion, until they
	 * run out or the budget does.
	 *
	 * Nothing is aged while the contour is off. The set never aged at all before this contour existed,
	 * and removing rows on a portal that publishes nothing would take documents off the page of recent
	 * ones without a single client being any better for it - the removal would not even be observable.
	 *
	 * @return int how many rows left the set
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SqlQueryException
	 * @throws SystemException
	 */
	public function run(int $budgetSeconds = self::TICK_BUDGET_SECONDS): int
	{
		if (!$this->feature->isEnabled())
		{
			return 0;
		}

		$agedBefore = DateTime::createFromTimestamp(
			($this->now)() - $this->limits->getInteractionWindowSeconds(),
		);
		$startedAt = microtime(true);

		$removed = 0;
		$afterFreshnessAt = null;
		$afterId = 0;

		while (true)
		{
			$rows = $this->readPortion($agedBefore, $afterFreshnessAt, $afterId);
			if ($rows === [])
			{
				break;
			}

			$announced = $this->publishTombstonesOf($rows);
			if ($announced !== [])
			{
				$forgotten = $this->forget($announced, $agedBefore);
				if ($forgotten < count($announced))
				{
					$this->takeBackTheTombstonesOfTheRowsThatStayed($announced);
				}
				$removed += $forgotten;
			}

			$last = $rows[array_key_last($rows)];
			$afterFreshnessAt = $last['UPDATE_TIME'];
			$afterId = (int)$last['ID'];

			if (count($rows) < $this->portion || microtime(true) - $startedAt >= $budgetSeconds)
			{
				break;
			}
		}

		$this->logger->debug(
			'the aging of the interaction set removed {removed} rows older than {agedBefore}',
			['removed' => $removed, 'agedBefore' => $agedBefore->toString()],
		);

		return $removed;
	}

	/**
	 * The oldest rows past the window, read through IX_DISK_TO_7.
	 *
	 * The rows of a portion are deleted once they are dealt with, so the walk would find the next ones
	 * at the very start of the stream either way. The keyset is carried all the same, and it is what
	 * keeps a portion the deletion failed on from being read, published and failed on again for the
	 * whole of the budget.
	 *
	 * @return array<int, array{ID: int|string, USER_ID: int|string, REAL_OBJECT_ID: int|string, UPDATE_TIME: DateTime}>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function readPortion(DateTime $agedBefore, ?DateTime $afterFreshnessAt, int $afterId): array
	{
		$query = TrackedObjectTable::query()
			->setSelect(['ID', 'USER_ID', 'REAL_OBJECT_ID', 'UPDATE_TIME'])
			->where('UPDATE_TIME', '<', $agedBefore)
			->setOrder(['UPDATE_TIME' => 'ASC', 'ID' => 'ASC'])
			->setLimit($this->portion)
		;

		if ($afterFreshnessAt !== null)
		{
			// The range on the key column stays a range so that the index still serves the ordering; the
			// tie on the identifier is a residual condition over the few rows sharing a second.
			$query
				->where('UPDATE_TIME', '>=', $afterFreshnessAt)
				->where(
					Query::filter()
						->logic('or')
						->where('UPDATE_TIME', '>', $afterFreshnessAt)
						->where('ID', '>', $afterId)
					,
				)
			;
		}

		return $query->fetchAll();
	}

	/**
	 * The rows of the portion the journal answered for, which are the only ones the walk may then take
	 * out of the set.
	 *
	 * A record is refused by an insert that failed or by an audience that could not be worked out, and
	 * the writer answers so rather than raising: the row it names is the last thing carrying the object
	 * to that user, so removing it after a refused record would end their visibility with nothing said
	 * about it and nothing left to say it from. A row deliberately passed over - a user who also sees
	 * the object by rights, an object that is no longer on the portal - is answered for and leaves.
	 *
	 * @param array<int, array{USER_ID: int|string, REAL_OBJECT_ID: int|string}> $rows
	 * @return array<int, array{USER_ID: int|string, REAL_OBJECT_ID: int|string}>
	 */
	private function publishTombstonesOf(array $rows): array
	{
		$announced = [];
		foreach ($rows as $row)
		{
			$published = $this->journal->publishPersonalTombstone(
				(int)$row['REAL_OBJECT_ID'],
				(int)$row['USER_ID'],
				TombstoneReason::InteractionAged,
			);

			if ($published)
			{
				$announced[] = $row;
			}
		}

		if (count($announced) < count($rows))
		{
			$this->logger->warning(
				'{kept} rows of the interaction set stayed: the journal did not take their tombstones',
				['kept' => count($rows) - count($announced)],
			);
		}

		return $announced;
	}

	/**
	 * The rows of the portion, taken out by their keys and by the window they fell out of.
	 *
	 * A portion cannot be expressed as a limit on the deletion itself in both supported databases, so
	 * the keys are read first and deleted by identifier - the same shape the cleanup of the snapshots of
	 * this contour is written in. The window is repeated in the deletion because the user is free to come
	 * back to the object while the portion is being published: a row whose stamp moved is a row the
	 * walk no longer has anything to say about, and it stays where it is.
	 *
	 * @param array<int, array{ID: int|string}> $rows
	 * @throws SqlQueryException
	 */
	private function forget(array $rows, DateTime $agedBefore): int
	{
		$connection = Application::getConnection();
		$connection->queryExecute(
			(new SqlExpression(
				'DELETE FROM ?# WHERE ?# IN (?@) AND ?# < ?',
				TrackedObjectTable::getTableName(),
				'ID',
				array_map(static fn (array $row): int => (int)$row['ID'], $rows),
				'UPDATE_TIME',
				$agedBefore,
			))->compile()
		);

		return $connection->getAffectedRowsCount();
	}

	/**
	 * The other half of keeping a refreshed row: the tombstone for it has already gone out.
	 *
	 * Nothing in the product takes it back on its own. The refresh of a row moves its stamp and publishes
	 * nothing ({@see \Bitrix\Disk\TrackedObjectManager::refresh()}), and the record of entering the set is
	 * written on the insert of a row and never on its refresh - so a client told to drop the object would
	 * not be told about it again until the object itself changed. The record is published here instead,
	 * and only for the rows the deletion left behind, which in an undisturbed tick is none of them.
	 *
	 * @param array<int, array{ID: int|string, USER_ID: int|string, REAL_OBJECT_ID: int|string}> $rows
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function takeBackTheTombstonesOfTheRowsThatStayed(array $rows): void
	{
		$stayed = $this->rowsStillInTheSet(array_map(static fn (array $row): int => (int)$row['ID'], $rows));

		foreach ($rows as $row)
		{
			if (isset($stayed[(int)$row['ID']]))
			{
				$this->journal->publishInteractionEntered(
					(int)$row['REAL_OBJECT_ID'],
					(int)$row['USER_ID'],
				);
			}
		}
	}

	/**
	 * @param int[] $ids
	 * @return array<int, true>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function rowsStillInTheSet(array $ids): array
	{
		$rows = TrackedObjectTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $ids)
			->fetchAll()
		;

		return array_fill_keys(array_map(static fn (array $row): int => (int)$row['ID'], $rows), true);
	}
}
