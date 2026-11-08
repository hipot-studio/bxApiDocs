<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Journal;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Repository\Sync\SyncAccessSnapshotRepository;
use Bitrix\Disk\Internal\Repository\Sync\SyncLogRepository;
use Bitrix\Disk\Internal\Repository\Sync\SyncRightsSnapshotRepository;
use Bitrix\Disk\Internal\Repository\Sync\SyncSessionRepository;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Internal\Service\Sync\SyncLimits;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Closure;
use Psr\Log\LoggerInterface;

/**
 * What keeps the five tables of the contour bounded: the journal by the retention the portal publishes,
 * and the three kinds of snapshot beside it by that same rule or by an age of their own.
 *
 * The cleanup is written round one requirement - it must never overtake a live cursor - and the
 * requirement is met by construction rather than by care. A cursor carries its own expiry, fixed when
 * the session was opened at the boundary of that session plus the retention
 * ({@see \Bitrix\Disk\Internal\Service\Sync\SessionService::create()}), and that boundary is earlier
 * than the row of the session itself. So while a cursor is still accepted its session is younger than
 * the retention, and a cleanup cutting at the retention cannot reach anything published since. A client
 * obeying the published maximum step ({@see SyncLimits::getPollIntervalMaxSeconds()}, a third of the
 * retention) is further inside still. Removing a record below a position the client has already passed
 * changes nothing either: the position is an identifier and the reading continues past it, not through
 * it.
 *
 * Three rules shape the walk, and each of them closes a failure that has already happened somewhere.
 *
 * Portions taken by a snapshot of identifiers, never one unbounded statement. The cleanup of the journal
 * of deletions next door is written the other way, and how long it runs on a large portal is an open
 * question - for a table read by live cursors that would be a direct risk to the requirement above.
 *
 * Dependent rows before the rows they hang off: the codes of a record go before the record. A run that
 * stopped between the two leaves records whose codes are already gone, and the next run finds those
 * records by the same age and finishes the pair. In the opposite order the codes would outlive their
 * record and nothing would ever look for them again.
 *
 * A named lock held around the taking of a portion and not around the tick. One name serves the whole
 * cleanup, because a database session holds a single named lock on MySQL 5.6 and taking a second
 * releases the first. The deletion runs outside the lock: it is addressed to identifiers, so two nodes
 * that took the same portion delete the same rows, and the repetition costs work rather than
 * correctness.
 *
 * That holds against another node of the cleanup and not against a writer. A pair that comes back in the
 * gap has its row rewritten in place, under the identifier the portion already carries, so every deletion
 * of the walk repeats the age the row was chosen by and takes out what is still old.
 *
 * Nothing here is gated on the switch of the contour. A portal that turns the contour off stops writing
 * to the journal but does not stop holding what it wrote, and a cleanup stopping with the switch would
 * turn "off" into "kept for ever". Unlike the aging of the interaction set, this walk touches no table
 * any other feature of the module reads.
 */
final class JournalCleaner
{
	/**
	 * How many rows one portion carries.
	 *
	 * A setting rather than a constant, because the one thing no test can answer is what a portion costs
	 * on a portal of years of accumulation - and that is the number a portal in trouble has to be able
	 * to turn down. It is kept here and not among the published limits of the contour: those are the
	 * numbers the portal announces to its clients, and the size of a portion is announced to nobody.
	 */
	public const OPTION_PORTION = 'disk_sync_cleanup_portion';

	public const DEFAULT_PORTION = 1000;

	/**
	 * How long an orphan snapshot of the codes of a root is kept.
	 *
	 * An orphan is the snapshot of a recalculation of rights that was interrupted or hung, and by the
	 * audit such a recalculation is a matter of hours: the healer restarts one session an hour, so the
	 * curing itself runs for hours. The threshold is deliberately longer than both that profile and the
	 * period of the reconciliation that reads these snapshots - one swept before the reconciliation
	 * reaches it would leave the record of a finished recalculation without its addressing.
	 */
	public const ORPHAN_RIGHTS_SNAPSHOT_HOURS = 48;

	/** One name for the whole cleanup: a database session holds a single named lock on MySQL 5.6. */
	private const LOCK_NAME = 'disk_sync_journal_cleanup';

	/** Nothing is gained by queuing for the lock: whoever holds it is doing this very work. */
	private const LOCK_TIMEOUT_SECONDS = 0;

	/**
	 * How long one tick works before it leaves the rest to the next one.
	 *
	 * This is the whole answer to "how long does the cleanup take on a portal of years of accumulation":
	 * one tick, this long. What is left over is met by the next tick, which starts from the oldest rows
	 * again.
	 */
	private const TICK_BUDGET_SECONDS = 20;

	private const MIN_PORTION = 1;

	private const SECONDS_IN_HOUR = 3600;

	private const LOGGER_CHANNEL = 'sync';

	private SyncLimits $limits;
	private SyncLogRepository $journal;
	private SyncSessionRepository $sessions;
	private SyncAccessSnapshotRepository $pairSnapshots;
	private SyncRightsSnapshotRepository $rightsSnapshots;
	private LoggerInterface $logger;
	private ?int $portion;
	private Closure $now;

	/**
	 * @param int|null $portion overrides the setting of the portal; the setting itself is read on every
	 *        tick, so that turning it down reaches a portal already in trouble without a deployment
	 * @param (callable(): int)|null $now the clock the boundaries are measured against, in unix time.
	 *        The budget of a tick is deliberately not read from it: a clock standing still - which is
	 *        what a test hands in - would turn the walk into one without an end.
	 */
	public function __construct(
		?SyncLimits $limits = null,
		?SyncLogRepository $journal = null,
		?SyncSessionRepository $sessions = null,
		?SyncAccessSnapshotRepository $pairSnapshots = null,
		?SyncRightsSnapshotRepository $rightsSnapshots = null,
		?LoggerInterface $logger = null,
		?int $portion = null,
		?callable $now = null,
	)
	{
		$this->limits = $limits ?? new SyncLimits();
		$this->journal = $journal ?? new SyncLogRepository();
		$this->sessions = $sessions ?? new SyncSessionRepository();
		$this->pairSnapshots = $pairSnapshots ?? new SyncAccessSnapshotRepository();
		$this->rightsSnapshots = $rightsSnapshots ?? new SyncRightsSnapshotRepository();
		$this->logger = $logger ?? LoggerFactory::create(self::LOGGER_CHANNEL, feature: 'journal cleanup');
		$this->portion = $portion === null ? null : max(self::MIN_PORTION, $portion);
		$this->now = $now === null ? static fn (): int => time() : Closure::fromCallable($now);
	}

	/**
	 * One tick: the journal first, then the rows that only mean anything while a cursor of the pair is
	 * alive, then the orphan snapshots of the roots.
	 *
	 * The journal goes first because it is the one table growing with the write rate of the portal; the
	 * others are bounded by the number of pairs and of running recalculations. A tick that spends its
	 * budget therefore spends it where the growth is.
	 *
	 * A tick that finds the lock taken stops there and leaves the rest to the node holding it, instead
	 * of walking the remaining tables beside it and doing the same work twice.
	 *
	 * @return array{
	 *     records: int,
	 *     codes: int,
	 *     sessions: int,
	 *     pairSnapshots: int,
	 *     rightsSnapshots: int,
	 *     budgetSpent: bool,
	 *     contended: bool
	 * }
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SqlQueryException
	 * @throws SystemException
	 */
	public function run(int $budgetSeconds = self::TICK_BUDGET_SECONDS): array
	{
		$now = ($this->now)();
		$retention = DateTime::createFromTimestamp($now - $this->limits->getJournalRetentionSeconds());
		$orphaned = DateTime::createFromTimestamp(
			$now - self::ORPHAN_RIGHTS_SNAPSHOT_HOURS * self::SECONDS_IN_HOUR,
		);

		$startedAt = microtime(true);
		$deadline = static fn (): bool => microtime(true) - $startedAt >= $budgetSeconds;

		// The whole of the state one tick keeps lives here, so that an instance of the cleaner can be run
		// again without carrying anything of the previous run.
		$contended = false;
		$take = function (callable $snapshot) use (&$contended): mixed {
			$taken = $this->takeUnderLock($snapshot);
			$contended = $contended || $taken === null;

			return $taken;
		};

		$journal = $this->cleanJournal($retention, $deadline, $take);
		$sessions = $contended ? 0 : $this->cleanSessions($retention, $deadline, $take);
		$pairSnapshots = $contended ? 0 : $this->cleanPairSnapshots($retention, $deadline, $take);
		$rightsSnapshots = $contended ? 0 : $this->cleanOrphanRightsSnapshots($orphaned, $deadline);

		$result = [
			'records' => $journal['records'],
			'codes' => $journal['codes'],
			'sessions' => $sessions,
			'pairSnapshots' => $pairSnapshots,
			'rightsSnapshots' => $rightsSnapshots,
			'budgetSpent' => $deadline(),
			'contended' => $contended,
		];

		$this->logger->debug(
			'the cleanup removed {records} records of the journal with {codes} codes, {sessions} sessions,'
			. ' {pairSnapshots} snapshots of pairs and {rightsSnapshots} orphan snapshots of roots,'
			. ' cutting the journal at {boundary}',
			$result + ['boundary' => $retention->toString()],
		);

		return $result;
	}

	/**
	 * The journal past its retention, portion by portion, codes before records.
	 *
	 * No keyset is carried: every identifier the portion took is deleted, so the next read finds the next
	 * oldest at the head of the same stream. A portion whose deletion raised ends the tick with the
	 * exception rather than being read and failed on again for the whole of the budget.
	 *
	 * @param callable(): bool $deadline
	 * @param callable(callable): mixed $take
	 * @return array{records: int, codes: int}
	 * @throws SqlQueryException
	 */
	private function cleanJournal(DateTime $olderThan, callable $deadline, callable $take): array
	{
		$portion = $this->portion();
		$records = 0;
		$codes = 0;

		while (true)
		{
			$ids = $take(fn (): array => $this->journal->findIdsOlderThan($olderThan, $portion));
			if (!is_array($ids) || $ids === [])
			{
				break;
			}

			$codes += $this->journal->deleteCodesOf($ids);
			$records += $this->journal->deleteRecords($ids);

			if (count($ids) < $portion || $deadline())
			{
				break;
			}
		}

		return ['records' => $records, 'codes' => $codes];
	}

	/**
	 * Sessions whose every cursor has expired.
	 *
	 * The row is not what a cursor is checked against - a position carries its own expiry and the reader
	 * of the journal never looks the session up - so removing it takes nothing away from a client. What
	 * it does take away is the only mark left that the pair is still working, which is why the boundary
	 * here is the retention and not the shorter life of the token of the initial fetch.
	 *
	 * @param callable(): bool $deadline
	 * @param callable(callable): mixed $take
	 * @throws SqlQueryException
	 */
	private function cleanSessions(DateTime $olderThan, callable $deadline, callable $take): int
	{
		$portion = $this->portion();
		$removed = 0;

		while (true)
		{
			$ids = $take(fn (): array => $this->sessions->findIdsCreatedBefore($olderThan, $portion));
			if (!is_array($ids) || $ids === [])
			{
				break;
			}

			$removed += $this->sessions->deleteByIds($ids, $olderThan);

			if (count($ids) < $portion || $deadline())
			{
				break;
			}
		}

		return $removed;
	}

	/**
	 * Snapshots of the codes of a pair that no live cursor can need any more.
	 *
	 * Two conditions and not one. The age of the row alone would be wrong: the stamp moves only when the
	 * codes of the pair diverge, so a pair polling for a year with a stable set of codes carries a stamp
	 * as old as its first session. Dropping its baseline would leave the narrowing that came next
	 * unreported until a new baseline was laid down - and a narrowing nobody reports is exactly the
	 * silence this snapshot exists to break.
	 *
	 * So the age only selects candidates, and whether the pair still holds a cursor is answered by its
	 * session: a cursor is accepted only while the session it was opened in is younger than the
	 * retention, so a pair without such a session has no live cursor at all.
	 *
	 * @param callable(): bool $deadline
	 * @param callable(callable): mixed $take
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SqlQueryException
	 * @throws SystemException
	 */
	private function cleanPairSnapshots(DateTime $olderThan, callable $deadline, callable $take): int
	{
		$portion = $this->portion();
		$removed = 0;
		$afterUpdatedAt = null;
		$afterId = 0;

		while (true)
		{
			$candidates = $take(
				fn (): array => $this->pairSnapshots->findAgedCandidates(
					$olderThan,
					$afterUpdatedAt,
					$afterId,
					$portion,
				),
			);
			if (!is_array($candidates) || $candidates === [])
			{
				break;
			}

			$removed += $this->pairSnapshots->deleteByIds(
				$this->withoutALiveCursor($candidates, $olderThan),
				$olderThan,
			);

			$last = $candidates[array_key_last($candidates)];
			$afterUpdatedAt = $last['UPDATED_AT'];
			$afterId = $last['ID'];

			if (count($candidates) < $portion || $deadline())
			{
				break;
			}
		}

		return $removed;
	}

	/**
	 * @param array<int, array{ID: int, USER_ID: int, APP_ID: string}> $candidates
	 * @return int[] the snapshots among them that may go
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function withoutALiveCursor(array $candidates, DateTime $sessionsSince): array
	{
		$userIds = array_values(array_unique(array_column($candidates, 'USER_ID')));

		$alive = [];
		foreach ($this->sessions->findPairsCreatedSince($userIds, $sessionsSince) as $pair)
		{
			$alive[self::pairKey($pair['USER_ID'], $pair['APP_ID'])] = true;
		}

		$ids = [];
		foreach ($candidates as $candidate)
		{
			if (!isset($alive[self::pairKey($candidate['USER_ID'], $candidate['APP_ID'])]))
			{
				$ids[] = $candidate['ID'];
			}
		}

		return $ids;
	}

	/**
	 * Snapshots of the codes of a root left behind by a recalculation that never finished.
	 *
	 * The one stage that takes no lock. It has no dependent rows, so there is no order to keep and
	 * nothing to leave halfway; its portion is a single pair of statements over a table bounded by the
	 * number of recalculations a portal runs, and a portion repeated by another node deletes the same
	 * rows.
	 *
	 * @param callable(): bool $deadline
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SqlQueryException
	 * @throws SystemException
	 */
	private function cleanOrphanRightsSnapshots(DateTime $olderThan, callable $deadline): int
	{
		$portion = $this->portion();
		$removed = 0;

		while (true)
		{
			$taken = $this->rightsSnapshots->deleteOlderThan($olderThan, $portion);
			$removed += $taken;

			if ($taken < $portion || $deadline())
			{
				break;
			}
		}

		return $removed;
	}

	/**
	 * The portion, taken while the lock is held and handed back before anything is deleted.
	 *
	 * @template T
	 * @param callable(): T $snapshot
	 * @return T|null null when the lock belongs to somebody else
	 */
	private function takeUnderLock(callable $snapshot): mixed
	{
		$connection = Application::getConnection();

		if (!$connection->lock(self::LOCK_NAME, self::LOCK_TIMEOUT_SECONDS))
		{
			$this->logger->debug('the cleanup left the tick to the node holding the lock');

			return null;
		}

		try
		{
			return $snapshot();
		}
		finally
		{
			$connection->unlock(self::LOCK_NAME);
		}
	}

	private function portion(): int
	{
		if ($this->portion !== null)
		{
			return $this->portion;
		}

		$configured = (int)Option::get(
			Driver::INTERNAL_MODULE_ID,
			self::OPTION_PORTION,
			(string)self::DEFAULT_PORTION,
		);

		return max(self::MIN_PORTION, $configured);
	}

	private static function pairKey(int $userId, string $appId): string
	{
		return $userId . '|' . $appId;
	}
}
