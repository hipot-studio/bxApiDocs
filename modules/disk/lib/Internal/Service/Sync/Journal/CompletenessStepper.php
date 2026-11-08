<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Journal;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Repository\Sync\SyncLogRepository;
use Bitrix\Disk\Internal\Repository\Sync\SyncRightsSnapshotRepository;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Internal\Service\Sync\Access\RightsAccessPublisher;
use Bitrix\Disk\Internal\Service\Sync\SyncFeatureOption;
use Bitrix\Disk\Internal\Service\Sync\SyncLimits;
use Bitrix\Disk\Internals\DeletedLogV2Table;
use Bitrix\Disk\Internals\ObjectPathTable;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Internals\Rights\Table\RightSetupSessionTable;
use Bitrix\Disk\Internals\StorageTable;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Closure;
use Psr\Log\LoggerInterface;

/**
 * The safety net under the journal: what the portal has a trace of and the journal never heard about.
 *
 * Everything that reaches the journal reaches it through one writer, and the writer is called from the
 * domain methods of Disk. Two things get past that: a mutation written below the domain methods - a
 * batch statement, a repair script - and a publication that raised and was swallowed so as not to break
 * the operation it reported. Neither is visible to a client: what it holds simply stops matching the
 * portal, silently, until its cursor expires. This walk is what turns that silence into a record.
 *
 * Three traces are read, and they are read in the order of what cannot be recovered any other way.
 *
 * The finished recalculations of rights come first. An edit of rights leaves no mark on the object and
 * no row in the log of deletions, so a record of it that was lost is lost for good - nothing else in
 * the portal remembers that a boundary of visibility moved. The other two sources are second chances
 * for changes the client would eventually reconcile by other means, so a tick that spends its budget
 * spends it on the source that has no other means.
 *
 * The sync stamp of an object comes second: it is moved by every update of a row, including a trashing
 * ({@see \Bitrix\Disk\BaseObject::markDeletedInternal()}), so an object whose stamp is younger than
 * anything the journal holds about it is a change that went unreported.
 *
 * The log of deletions v2 comes last. It is never handed to a method of the contract - it is an
 * internal trace and nothing more - and what it can answer is narrow, for the reason set out over
 * {@see catchUpDeletions()}.
 *
 * Two properties hold across all three.
 *
 * A record is written only when the journal holds none, and that predicate is the whole of the
 * idempotency: a record the walk wrote is itself what stops the next tick from writing it again.
 * Windows are therefore allowed to overlap - rechecking is cheaper than a gap.
 *
 * Nothing is checked one object at a time. A portion is read, and the journal is asked about the whole
 * portion in one statement; only the objects that came back uncovered are touched individually, and on
 * a healthy portal there are none.
 */
final class CompletenessStepper
{
	/**
	 * How many rows one portion carries.
	 *
	 * A setting for the same reason the cleanup of the journal has one: what a portion costs on a portal
	 * of hundreds of thousands of objects is the one number no test can answer, and it is the number a
	 * portal in trouble has to be able to turn down. It is kept here rather than among the published
	 * limits, because those are the numbers the portal announces to its clients and this one is
	 * announced to nobody.
	 */
	public const OPTION_PORTION = 'disk_sync_completeness_portion';

	public const DEFAULT_PORTION = 500;

	/**
	 * Where each of the three walks stands, as a keyset of the column it walks by and the identifier
	 * that breaks its ties.
	 *
	 * One stamp is carried by many objects - a move stamps a whole subtree with a single moment - so a
	 * watermark that was a date alone would either repeat a batch for ever or step over the rest of it.
	 */
	public const OPTION_RIGHTS_WATERMARK_AT = 'disk_sync_completeness_rights_at';
	public const OPTION_RIGHTS_WATERMARK_ID = 'disk_sync_completeness_rights_id';
	public const OPTION_MARK_WATERMARK_AT = 'disk_sync_completeness_mark_at';
	public const OPTION_MARK_WATERMARK_ID = 'disk_sync_completeness_mark_id';
	public const OPTION_DELETION_WATERMARK_AT = 'disk_sync_completeness_deletion_at';
	public const OPTION_DELETION_WATERMARK_ID = 'disk_sync_completeness_deletion_id';

	/**
	 * How long one tick works before it leaves the rest to the next one. The same budget the two other
	 * background mechanisms of the contour run on.
	 */
	private const TICK_BUDGET_SECONDS = 20;

	/**
	 * How far around a deletion its own cascade is looked for in the log of deletions.
	 *
	 * The rows of one cascade are written across the request that performs it, so the root - whose
	 * tombstone answers for the whole subtree - can stand well away from the children in time. Bounded
	 * by the life of a request rather than by the size of a subtree.
	 */
	private const CASCADE_GRACE_SECONDS = 600;

	/**
	 * How many rows of the log of deletions one look around a cascade may read. A cascade wider than
	 * this leaves the tick without an answer, and a tick without an answer publishes nothing.
	 */
	private const CASCADE_LOOKUP_LIMIT = 5000;

	/** How many forks of one recalculation of rights are walked back looking for its snapshot. */
	private const MAX_FORK_DEPTH = 8;

	/** One name for the whole walk: a database session holds a single named lock on MySQL 5.6. */
	private const LOCK_NAME = 'disk_sync_completeness';

	/** Nothing is gained by queuing: whoever holds the lock is walking this very window. */
	private const LOCK_TIMEOUT_SECONDS = 0;

	private const DATE_FORMAT = 'Y-m-d H:i:s';

	private const MIN_PORTION = 1;

	private const LOGGER_CHANNEL = 'sync';

	/** @var string[] the kinds of resource a record of the journal names; the leading column of IX_DISK_SL_1 */
	private const RESOURCE_TYPES = [ChangeRecord::RESOURCE_FILE, ChangeRecord::RESOURCE_FOLDER];

	private SyncLimits $limits;
	private SyncLogRepository $journal;
	private SyncRightsSnapshotRepository $rightsSnapshots;
	private JournalWriter $writer;
	private RightsAccessPublisher $rights;
	private SyncFeatureOption $feature;
	private LoggerInterface $logger;
	private ?int $portion;
	private Closure $now;

	/**
	 * @param int|null $portion overrides the setting of the portal; the setting itself is read on every
	 *        tick, so that turning it down reaches a portal already in trouble without a deployment
	 * @param (callable(): int)|null $now the clock the windows are measured against, in unix time. The
	 *        budget of a tick is deliberately not read from it: a clock standing still - which is what a
	 *        test hands in - would turn the walk into one without an end.
	 */
	public function __construct(
		?SyncLimits $limits = null,
		?SyncLogRepository $journal = null,
		?SyncRightsSnapshotRepository $rightsSnapshots = null,
		?JournalWriter $writer = null,
		?RightsAccessPublisher $rights = null,
		?SyncFeatureOption $feature = null,
		?LoggerInterface $logger = null,
		?int $portion = null,
		?callable $now = null,
	)
	{
		$this->limits = $limits ?? new SyncLimits();
		$this->journal = $journal ?? new SyncLogRepository();
		$this->rightsSnapshots = $rightsSnapshots ?? new SyncRightsSnapshotRepository();
		$this->writer = $writer ?? new JournalWriter();
		$this->rights = $rights ?? new RightsAccessPublisher();
		$this->feature = $feature ?? new SyncFeatureOption();
		$this->logger = $logger ?? LoggerFactory::create(self::LOGGER_CHANNEL, feature: 'completeness');
		$this->portion = $portion === null ? null : max(self::MIN_PORTION, $portion);
		$this->now = $now === null ? static fn (): int => time() : Closure::fromCallable($now);
	}

	/**
	 * One tick over the three sources, each inside its own window.
	 *
	 * Every window ends a safe window behind the current instant. A record is written just after the
	 * mutation it reports, so the youngest tail of any of these traces holds changes whose records are
	 * still on their way; checking it would publish a second record for every one of them.
	 *
	 * The lock is held for the whole tick and not, as in the cleanup, around the taking of a portion.
	 * There the repetition of a portion by another node deletes the same rows and costs work alone;
	 * here it publishes a second record, and a duplicate record is what this walk exists to avoid.
	 *
	 * @return array{
	 *     rights: int,
	 *     marks: int,
	 *     deletions: int,
	 *     budgetSpent: bool,
	 *     contended: bool,
	 *     skipped: bool,
	 *     lagSeconds: int
	 * }
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function run(int $budgetSeconds = self::TICK_BUDGET_SECONDS): array
	{
		$now = ($this->now)();
		$upper = DateTime::createFromTimestamp($now - $this->limits->getSafeWindowSeconds());

		if (!$this->feature->isEnabled())
		{
			// The watermarks are carried forward all the same. A portal registers the agent long before
			// it switches the contour on, and a walk that stood still while the contour was off would
			// meet the whole retention on the first tick after it - every object changed in a month at
			// once, none of them a miss, because while the contour was off there was nothing to miss.
			$this->fastForward($upper);

			return self::result(skipped: true, lag: 0);
		}

		$connection = Application::getConnection();
		if (!$connection->lock(self::LOCK_NAME, self::LOCK_TIMEOUT_SECONDS))
		{
			$this->logger->debug('the reconciliation left the tick to the node holding the lock');

			return self::result(contended: true, lag: $this->lagSeconds($now));
		}

		$startedAt = microtime(true);
		$deadline = static fn (): bool => microtime(true) - $startedAt >= $budgetSeconds;

		try
		{
			// Each source takes at least one portion and then answers to the budget, so a tick spent on
			// one of them still moves the other two. Their order is what a budget already spent decides:
			// what the walk gets through first is what nothing else in the portal can recover.
			$rights = $this->catchUpRecalculations($now, $upper, $deadline);
			$marks = $this->catchUpChangedObjects($now, $upper, $deadline);
			$deletions = $this->catchUpDeletions($now, $upper, $deadline);
		}
		finally
		{
			$connection->unlock(self::LOCK_NAME);
		}

		$result = self::result(
			rights: $rights,
			marks: $marks,
			deletions: $deletions,
			budgetSpent: $deadline(),
			lag: $this->lagSeconds($now),
		);

		$this->logger->debug(
			'the reconciliation wrote {rights} records of a recalculation of rights, {marks} of a change'
			. ' of an object and {deletions} of a deletion; the oldest window trails the portal by'
			. ' {lagSeconds} seconds',
			$result,
		);

		return $result;
	}

	/**
	 * Recalculations of rights that finished without their record.
	 *
	 * The record of an edit of rights is published when the recalculation of the materialized rights is
	 * over, and there is more than one way for a recalculation to reach that point without anybody
	 * publishing it. A session that hung is restarted hours later by the healer of the module
	 * ({@see \Bitrix\Disk\Internals\Rights\Healer::restartSetupSession()}), which reaches the rebuilder
	 * through a path that knows nothing of the journal; the session it registers finishes normally and
	 * silently. That path alone makes this source the one the phase cannot do without.
	 *
	 * @param callable(): bool $deadline
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function catchUpRecalculations(int $now, DateTime $upper, callable $deadline): int
	{
		$portion = $this->portion();
		$written = 0;
		[$after, $afterId] = $this->watermark(
			self::OPTION_RIGHTS_WATERMARK_AT,
			self::OPTION_RIGHTS_WATERMARK_ID,
			$now,
		);

		while (true)
		{
			$rows = self::keyset(
				RightSetupSessionTable::query()
					->setSelect(['ID', 'PARENT_ID', 'OBJECT_ID', 'CREATE_TIME'])
					->where('STATUS', RightSetupSessionTable::STATUS_FINISHED)
				,
				'CREATE_TIME',
				$after,
				$afterId,
				$upper,
				$portion,
			)->fetchAll();

			if ($rows === [])
			{
				break;
			}

			$written += $this->publishMissingRecalculations($rows);

			$last = $rows[array_key_last($rows)];
			$after = $last['CREATE_TIME'];
			$afterId = (int)$last['ID'];
			$this->writeWatermark(
				self::OPTION_RIGHTS_WATERMARK_AT,
				self::OPTION_RIGHTS_WATERMARK_ID,
				$after,
				$afterId,
			);

			if (count($rows) < $portion || $deadline())
			{
				break;
			}
		}

		return $written;
	}

	/**
	 * @param array<int, array{ID: int|string, PARENT_ID: int|string|null, OBJECT_ID: int|string, CREATE_TIME: DateTime}> $rows
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function publishMissingRecalculations(array $rows): int
	{
		$objectIds = array_map(static fn (array $row): int => (int)$row['OBJECT_ID'], $rows);
		$born = self::creationTimesOf($objectIds);
		$published = $this->journal->findLatestAccessRecordTimes(
			$objectIds,
			self::RESOURCE_TYPES,
			ChangeRecord::SCOPE_SUBTREE,
			self::shiftedBack(self::earliestOf($rows, 'CREATE_TIME')),
		);

		$missing = [];
		foreach ($rows as $row)
		{
			$objectId = (int)$row['OBJECT_ID'];
			$objectBorn = $born[$objectId] ?? null;

			// An object that is gone leaves no boundary anybody could be sent to look at, and the
			// writer refuses such a record on the way out anyway.
			if ($objectId <= 0 || $objectBorn === null)
			{
				continue;
			}

			// The laying down of the first rights of a new object is not an edit of them. Every file and
			// folder created with rights of its own registers a recalculation that finishes and
			// publishes nothing, deliberately: a new object has no audience to lose, and the readers it
			// has are the ones the record of its creation already reaches.
			if (
				$row['CREATE_TIME']->getTimestamp()
				<= $objectBorn->getTimestamp() + SyncLimits::CLOCK_TOLERANCE_SECONDS
			)
			{
				continue;
			}

			if (self::isCovered($published[$objectId] ?? null, $row['CREATE_TIME']))
			{
				continue;
			}

			$missing[(int)$row['ID']] = $objectId;
		}

		if ($missing === [])
		{
			return 0;
		}

		$written = 0;
		foreach ($this->snapshotHoldersOf($missing) as $sessionId => $holderId)
		{
			// The publisher of P6 does the whole of the writing: it reads the codes the root carried
			// before the edit, unites them with the ones it carries now and takes the snapshot away
			// afterwards. Reaching past it would mean keeping a second copy of that rule here - and of
			// the other half of it, which is that a recalculation moving no boundary publishes nothing:
			// a session left recordless on purpose is met here every time and has to be answered the
			// same way every time.
			if ($this->rights->publishRecalculated($holderId, $missing[$sessionId]))
			{
				$written++;
			}
		}

		return $written;
	}

	/**
	 * Which session holds the codes captured before each edit.
	 *
	 * Normally the session that finished. A recalculation that hung is not restarted but forked: the
	 * healer registers a child session and the snapshot stays with the parent
	 * ({@see \Bitrix\Disk\Internals\Rights\SetupSession::forkAndRestart()}), so the chain of parents is
	 * walked until the snapshot turns up. A session whose whole chain has none is published all the
	 * same, with the half "before" empty - which is what the live path does too, and is the shape of
	 * every recalculation the healer performed.
	 *
	 * @param array<int, int> $missing session identifier => object of its root
	 * @return array<int, int> session identifier => the session holding its snapshot
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function snapshotHoldersOf(array $missing): array
	{
		$holders = [];
		$pending = [];
		foreach (array_keys($missing) as $sessionId)
		{
			$pending[$sessionId] = $sessionId;
		}

		for ($depth = 0; $depth < self::MAX_FORK_DEPTH && $pending !== []; $depth++)
		{
			$found = $this->rightsSnapshots->findSessionsWithSnapshot(array_values($pending));

			$climbing = [];
			foreach ($pending as $sessionId => $candidateId)
			{
				if (isset($found[$candidateId]))
				{
					$holders[$sessionId] = $candidateId;
				}
				else
				{
					$climbing[$sessionId] = $candidateId;
				}
			}

			$pending = [];
			if ($climbing === [])
			{
				break;
			}

			$parents = self::parentsOf(array_values($climbing));

			foreach ($climbing as $sessionId => $candidateId)
			{
				$parentId = $parents[$candidateId] ?? 0;
				if ($parentId > 0)
				{
					$pending[$sessionId] = $parentId;
				}
				else
				{
					// No parent and no snapshot anywhere up the chain: the recalculation of the healer.
					$holders[$sessionId] = $sessionId;
				}
			}
		}

		foreach (array_keys($pending) as $sessionId)
		{
			$holders[$sessionId] = $sessionId;
		}

		return $holders;
	}

	/**
	 * Objects whose sync stamp is younger than anything the journal holds about them.
	 *
	 * The stamp is moved by every update of the row, so what is caught here is any change of an object
	 * that went unreported - including a trashing, which moves the stamp of its own accord since
	 * `[P4.T6]`.
	 *
	 * @param callable(): bool $deadline
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function catchUpChangedObjects(int $now, DateTime $upper, callable $deadline): int
	{
		$portion = $this->portion();
		$written = 0;
		[$after, $afterId] = $this->watermark(
			self::OPTION_MARK_WATERMARK_AT,
			self::OPTION_MARK_WATERMARK_ID,
			$now,
		);

		while (true)
		{
			$rows = self::keyset(
				ObjectTable::query()->setSelect(['ID', 'SYNC_UPDATE_TIME']),
				'SYNC_UPDATE_TIME',
				$after,
				$afterId,
				$upper,
				$portion,
			)->fetchAll();

			if ($rows === [])
			{
				break;
			}

			$written += $this->publishUnreportedChanges($rows);

			$last = $rows[array_key_last($rows)];
			$after = $last['SYNC_UPDATE_TIME'];
			$afterId = (int)$last['ID'];
			$this->writeWatermark(
				self::OPTION_MARK_WATERMARK_AT,
				self::OPTION_MARK_WATERMARK_ID,
				$after,
				$afterId,
			);

			if (count($rows) < $portion || $deadline())
			{
				break;
			}
		}

		return $written;
	}

	/**
	 * @param array<int, array{ID: int|string, SYNC_UPDATE_TIME: DateTime}> $rows
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function publishUnreportedChanges(array $rows): int
	{
		$ids = array_map(static fn (array $row): int => (int)$row['ID'], $rows);
		$ancestors = self::ancestorsOf($ids);
		$realOf = self::realObjectsOf(self::flatten($ancestors));

		$latest = $this->journal->findLatestObjectRecordTimes(
			array_values(array_unique($realOf)),
			self::RESOURCE_TYPES,
			self::shiftedBack(self::earliestOf($rows, 'SYNC_UPDATE_TIME')),
		);

		$unreported = [];
		foreach ($rows as $row)
		{
			$id = (int)$row['ID'];
			$path = $ancestors[$id] ?? [$id];
			if (!self::isReportedByItselfOrAnAncestor($path, $realOf, $latest, $row['SYNC_UPDATE_TIME']))
			{
				$unreported[] = $id;
			}
		}

		if ($unreported === [])
		{
			return 0;
		}

		$written = 0;
		foreach (BaseObject::loadBatchById($unreported) as $object)
		{
			// An update and never a guess at the operation. What moved the stamp is not written down
			// anywhere, and the type of a record is what it is read by and not what it is acted on: the
			// card is assembled from the object as it stands now, so "something about this object
			// changed, fetch it again" is both all this walk knows and all the client needs.
			$this->writer->publishObjectChange($object, ChangeType::Updated);
			$written++;
		}

		return $written;
	}

	/**
	 * Whether the object or any ancestor of it carries a record of the journal no older than the stamp.
	 *
	 * The ancestors are what keeps a cascade from being republished a thousand times over. A move or a
	 * trashing stamps the whole subtree and publishes one record on the root of it, deliberately - so
	 * an object whose own identifier the journal never names is reported all the same, by the record
	 * standing above it.
	 *
	 * @param int[] $path the object and its ancestors
	 * @param array<int, int> $realOf identifier => the real object behind it
	 * @param array<int, DateTime> $latest real object => the moment of its newest record
	 */
	private static function isReportedByItselfOrAnAncestor(
		array $path,
		array $realOf,
		array $latest,
		DateTime $stamp,
	): bool
	{
		foreach ($path as $id)
		{
			$realId = $realOf[$id] ?? $id;
			if (self::isCovered($latest[$realId] ?? null, $stamp))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Deletions the log of deletions v2 knows about and the journal does not.
	 *
	 * The narrowest of the three sources, and narrow by the nature of what it reads rather than by
	 * choice. Three kinds of row in that log are not misses at all and are taken out before anything
	 * else: a row whose object is still there is a trashing or a move, which the sync stamp answers
	 * for; a row of an object the journal never names is a link, which carries no record of its own
	 * because the journal reports the real object behind it; and a row whose object already has a
	 * tombstone was reported.
	 *
	 * What is left is a deletion of an object the journal knew and never buried - and one more thing
	 * that is not a miss: the children of a cascade, which are buried by the single tombstone of their
	 * root. The row of that root stands in the same log, in the same storage, minutes away at most - so
	 * a storage whose deletions of that stretch carry a tombstone anywhere among them is left alone.
	 * The cost of that rule is what it also masks: a lost tombstone in a storage that was deleting other
	 * things at the same time.
	 *
	 * What goes out is not a tombstone. The object is gone, its rights are gone with it, and there is
	 * nobody left to address one to; what the portal still knows is which storage it was in, so the
	 * record says that the boundary of visibility inside that storage moved and leaves the client to
	 * find out what by comparing it. The readers of that storage are the audience, which is fewer than
	 * everyone who could see the object - see the phase report for who is left out.
	 *
	 * @param callable(): bool $deadline
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function catchUpDeletions(int $now, DateTime $upper, callable $deadline): int
	{
		$portion = $this->portion();
		$written = 0;
		[$after, $afterId] = $this->watermark(
			self::OPTION_DELETION_WATERMARK_AT,
			self::OPTION_DELETION_WATERMARK_ID,
			$now,
		);

		while (true)
		{
			$rows = self::keyset(
				DeletedLogV2Table::query()->setSelect(['ID', 'OBJECT_ID', 'STORAGE_ID', 'CREATE_TIME']),
				'CREATE_TIME',
				$after,
				$afterId,
				$upper,
				$portion,
			)->fetchAll();

			if ($rows === [])
			{
				break;
			}

			$written += $this->publishUnreportedDeletions($rows, $now);

			$last = $rows[array_key_last($rows)];
			$after = $last['CREATE_TIME'];
			$afterId = (int)$last['ID'];
			$this->writeWatermark(
				self::OPTION_DELETION_WATERMARK_AT,
				self::OPTION_DELETION_WATERMARK_ID,
				$after,
				$afterId,
			);

			if (count($rows) < $portion || $deadline())
			{
				break;
			}
		}

		return $written;
	}

	/**
	 * @param array<int, array{OBJECT_ID: int|string, STORAGE_ID: int|string, CREATE_TIME: DateTime}> $rows
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function publishUnreportedDeletions(array $rows, int $now): int
	{
		$gone = self::goneObjectsOf(array_map(static fn (array $row): int => (int)$row['OBJECT_ID'], $rows));
		if ($gone === [])
		{
			return 0;
		}

		$retentionStart = DateTime::createFromTimestamp($now - $this->limits->getJournalRetentionSeconds());
		$known = $this->journal->findLatestObjectRecordTimes($gone, self::RESOURCE_TYPES, $retentionStart);
		if ($known === [])
		{
			return 0;
		}

		$earliest = self::shiftedBack(self::earliestOf($rows, 'CREATE_TIME'));
		$buried = $this->journal->findLatestObjectRecordTimes(
			array_keys($known),
			self::RESOURCE_TYPES,
			$earliest,
			ChangeRecord::OPERATION_TOMBSTONE,
		);

		/** @var array<int, DateTime> $unreported storage => the earliest deletion of it left unreported */
		$unreported = [];
		foreach ($rows as $row)
		{
			$objectId = (int)$row['OBJECT_ID'];
			if (!isset($known[$objectId]) || self::isCovered($buried[$objectId] ?? null, $row['CREATE_TIME']))
			{
				continue;
			}

			$storageId = (int)$row['STORAGE_ID'];
			$standing = $unreported[$storageId] ?? null;
			if ($standing === null || $row['CREATE_TIME']->getTimestamp() < $standing->getTimestamp())
			{
				$unreported[$storageId] = $row['CREATE_TIME'];
			}
		}

		$unreported = $this->withoutCascades($unreported);

		return $unreported === [] ? 0 : $this->tellStoragesOfTheirDeletions($unreported);
	}

	/**
	 * Storages whose unreported deletions are the children of a cascade its root already answered for.
	 *
	 * @param array<int, DateTime> $unreported storage => its earliest unreported deletion
	 * @return array<int, DateTime> the same, without the storages a cascade explains
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function withoutCascades(array $unreported): array
	{
		if ($unreported === [])
		{
			return [];
		}

		$moments = array_map(static fn (DateTime $at): int => $at->getTimestamp(), $unreported);
		$from = DateTime::createFromTimestamp(min($moments) - self::CASCADE_GRACE_SECONDS);
		$to = DateTime::createFromTimestamp(max($moments) + self::CASCADE_GRACE_SECONDS);

		$neighbours = DeletedLogV2Table::query()
			->setSelect(['OBJECT_ID', 'STORAGE_ID'])
			->whereIn('STORAGE_ID', array_keys($unreported))
			->where('CREATE_TIME', '>=', $from)
			->where('CREATE_TIME', '<=', $to)
			->setLimit(self::CASCADE_LOOKUP_LIMIT)
			->fetchAll()
		;

		if (count($neighbours) >= self::CASCADE_LOOKUP_LIMIT)
		{
			// The stretch was read short, so whether a cascade explains these rows is unanswered - and
			// an unanswered question publishes nothing. Silence here costs a deletion its record;
			// speaking would send every reader of the storage to compare it over a cascade that was
			// reported properly, which is the more expensive of the two mistakes by far.
			$this->logger->warning(
				'the reconciliation read no further than {limit} deletions and left the storages unjudged',
				['limit' => self::CASCADE_LOOKUP_LIMIT, 'storages' => count($unreported)],
			);

			return [];
		}

		$explained = $this->journal->findLatestObjectRecordTimes(
			array_map(static fn (array $row): int => (int)$row['OBJECT_ID'], $neighbours),
			self::RESOURCE_TYPES,
			self::shiftedBack($from),
			ChangeRecord::OPERATION_TOMBSTONE,
		);

		foreach ($neighbours as $row)
		{
			if (isset($explained[(int)$row['OBJECT_ID']]))
			{
				unset($unreported[(int)$row['STORAGE_ID']]);
			}
		}

		return $unreported;
	}

	/**
	 * @param array<int, DateTime> $unreported storage => its earliest unreported deletion
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function tellStoragesOfTheirDeletions(array $unreported): int
	{
		$roots = [];
		$rows = StorageTable::query()
			->setSelect(['ID', 'ROOT_OBJECT_ID'])
			->whereIn('ID', array_keys($unreported))
			->fetchAll()
		;
		foreach ($rows as $row)
		{
			$rootId = (int)$row['ROOT_OBJECT_ID'];
			if ($rootId > 0)
			{
				$roots[(int)$row['ID']] = $rootId;
			}
		}

		if ($roots === [])
		{
			return 0;
		}

		$moments = array_map(static fn (DateTime $at): int => $at->getTimestamp(), $unreported);
		$told = $this->journal->findLatestAccessRecordTimes(
			array_values($roots),
			self::RESOURCE_TYPES,
			ChangeRecord::SCOPE_SUBTREE,
			DateTime::createFromTimestamp(min($moments) - SyncLimits::CLOCK_TOLERANCE_SECONDS),
		);

		$written = 0;
		foreach ($roots as $storageId => $rootId)
		{
			if (self::isCovered($told[$rootId] ?? null, $unreported[$storageId]))
			{
				continue;
			}

			// The codes are left to the writer: it reads the ones the root carries now, and those are
			// exactly the readers of the storage the record is meant for.
			$this->writer->publishAccessChanged(ChangeRecord::SCOPE_SUBTREE, $rootId, true, []);
			$written++;
		}

		return $written;
	}

	/**
	 * The window of a walk, as a keyset over its own column and the identifier that breaks the ties in
	 * it.
	 *
	 * The range on the column stays a range so that the index still serves the ordering; the tie on the
	 * identifier is a residual condition over the few rows sharing a second.
	 */
	private static function keyset(
		Query $query,
		string $column,
		DateTime $after,
		int $afterId,
		DateTime $upper,
		int $portion,
	): Query
	{
		return $query
			->where($column, '>=', $after)
			->where($column, '<=', $upper)
			->where(
				Query::filter()
					->logic('or')
					->where($column, '>', $after)
					->where('ID', '>', $afterId)
				,
			)
			->setOrder([$column => 'ASC', 'ID' => 'ASC'])
			->setLimit($portion)
		;
	}

	/**
	 * The objects among the given ones that are no longer rows of the portal.
	 *
	 * @param int[] $objectIds
	 * @return int[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private static function goneObjectsOf(array $objectIds): array
	{
		$ids = array_values(array_unique(array_filter($objectIds, static fn (int $id): bool => $id > 0)));
		if ($ids === [])
		{
			return [];
		}

		$alive = [];
		foreach (ObjectTable::query()->setSelect(['ID'])->whereIn('ID', $ids)->fetchAll() as $row)
		{
			$alive[(int)$row['ID']] = true;
		}

		return array_values(array_filter($ids, static fn (int $id): bool => !isset($alive[$id])));
	}

	/**
	 * Each of the given objects together with its ancestors, read off the closure table of the tree.
	 *
	 * @param int[] $ids
	 * @return array<int, int[]> object => itself and its ancestors
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private static function ancestorsOf(array $ids): array
	{
		$paths = [];
		foreach ($ids as $id)
		{
			$paths[$id] = [$id];
		}

		$rows = ObjectPathTable::query()
			->setSelect(['OBJECT_ID', 'PARENT_ID'])
			->whereIn('OBJECT_ID', $ids)
			->fetchAll()
		;

		foreach ($rows as $row)
		{
			$objectId = (int)$row['OBJECT_ID'];
			$parentId = (int)$row['PARENT_ID'];
			if ($parentId !== $objectId && isset($paths[$objectId]))
			{
				$paths[$objectId][] = $parentId;
			}
		}

		return $paths;
	}

	/**
	 * When each of the given objects came into being, for the ones that still exist.
	 *
	 * @param int[] $objectIds
	 * @return array<int, DateTime>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private static function creationTimesOf(array $objectIds): array
	{
		$ids = array_values(array_unique(array_filter($objectIds, static fn (int $id): bool => $id > 0)));
		if ($ids === [])
		{
			return [];
		}

		$born = [];
		$rows = ObjectTable::query()->setSelect(['ID', 'CREATE_TIME'])->whereIn('ID', $ids)->fetchAll();
		foreach ($rows as $row)
		{
			if ($row['CREATE_TIME'] instanceof DateTime)
			{
				$born[(int)$row['ID']] = $row['CREATE_TIME'];
			}
		}

		return $born;
	}

	/**
	 * The real object behind each of the given identifiers.
	 *
	 * A link is a row of the same table with a stamp of its own, and the journal reports the object it
	 * points at - so a link compared against the records of its own identifier would look unreported for
	 * ever.
	 *
	 * @param int[] $ids
	 * @return array<int, int>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private static function realObjectsOf(array $ids): array
	{
		if ($ids === [])
		{
			return [];
		}

		$real = [];
		$rows = ObjectTable::query()->setSelect(['ID', 'REAL_OBJECT_ID'])->whereIn('ID', $ids)->fetchAll();
		foreach ($rows as $row)
		{
			$realId = (int)$row['REAL_OBJECT_ID'];
			$real[(int)$row['ID']] = $realId > 0 ? $realId : (int)$row['ID'];
		}

		return $real;
	}

	/**
	 * @param int[] $sessionIds
	 * @return array<int, int> session => its parent
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private static function parentsOf(array $sessionIds): array
	{
		$parents = [];
		$rows = RightSetupSessionTable::query()
			->setSelect(['ID', 'PARENT_ID'])
			->whereIn('ID', array_values(array_unique($sessionIds)))
			->fetchAll()
		;

		foreach ($rows as $row)
		{
			$parents[(int)$row['ID']] = (int)($row['PARENT_ID'] ?? 0);
		}

		return $parents;
	}

	/**
	 * Whether a record standing at the given moment answers for a trace stamped at the other.
	 *
	 * The two are read off different clocks, and the tolerance is what that comparison is allowed to
	 * lose. Nothing else works against the check: a record is published after the row it reports, and in
	 * a cascade the descendants are stamped before the root publishes, which leaves the drift between
	 * the nodes as the only reason a record can look older than what it reports.
	 */
	private static function isCovered(?DateTime $recordAt, DateTime $traceAt): bool
	{
		if ($recordAt === null)
		{
			return false;
		}

		return $recordAt->getTimestamp() >= $traceAt->getTimestamp() - SyncLimits::CLOCK_TOLERANCE_SECONDS;
	}

	private static function shiftedBack(DateTime $moment): DateTime
	{
		return DateTime::createFromTimestamp($moment->getTimestamp() - SyncLimits::CLOCK_TOLERANCE_SECONDS);
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private static function earliestOf(array $rows, string $column): DateTime
	{
		$earliest = null;
		foreach ($rows as $row)
		{
			$moment = $row[$column];
			if ($moment instanceof DateTime && ($earliest === null || $moment->getTimestamp() < $earliest->getTimestamp()))
			{
				$earliest = $moment;
			}
		}

		return $earliest ?? new DateTime();
	}

	/**
	 * @param array<int, int[]> $lists
	 * @return int[]
	 */
	private static function flatten(array $lists): array
	{
		$flat = [];
		foreach ($lists as $list)
		{
			foreach ($list as $id)
			{
				$flat[$id] = $id;
			}
		}

		return array_values($flat);
	}

	/**
	 * Where a walk stands, or the far end of the retention when it has never run.
	 *
	 * The retention and not the whole history of the portal: a trace older than the journal keeps
	 * records is one whose client has an expired cursor anyway, and a cursor that expired is settled by
	 * a full reconciliation rather than by a record.
	 *
	 * @return array{DateTime, int}
	 */
	private function watermark(string $atOption, string $idOption, int $now): array
	{
		$stored = (string)Option::get(Driver::INTERNAL_MODULE_ID, $atOption, '');
		if ($stored === '')
		{
			return [
				DateTime::createFromTimestamp($now - $this->limits->getJournalRetentionSeconds()),
				0,
			];
		}

		return [
			new DateTime($stored, self::DATE_FORMAT),
			(int)Option::get(Driver::INTERNAL_MODULE_ID, $idOption, '0'),
		];
	}

	private function writeWatermark(string $atOption, string $idOption, DateTime $at, int $id): void
	{
		Option::set(Driver::INTERNAL_MODULE_ID, $atOption, $at->format(self::DATE_FORMAT));
		Option::set(Driver::INTERNAL_MODULE_ID, $idOption, (string)$id);
	}

	private function fastForward(DateTime $upper): void
	{
		foreach (self::watermarkOptions() as [$atOption, $idOption])
		{
			$this->writeWatermark($atOption, $idOption, $upper, 0);
		}
	}

	/**
	 * How far behind the portal the oldest of the three walks stands - the one number that says whether
	 * the reconciliation is keeping up.
	 */
	private function lagSeconds(int $now): int
	{
		$oldest = $now;
		foreach (self::watermarkOptions() as [$atOption, $idOption])
		{
			[$at] = $this->watermark($atOption, $idOption, $now);
			$oldest = min($oldest, $at->getTimestamp());
		}

		return max(0, $now - $oldest);
	}

	/**
	 * @return array<int, array{string, string}>
	 */
	private static function watermarkOptions(): array
	{
		return [
			[self::OPTION_RIGHTS_WATERMARK_AT, self::OPTION_RIGHTS_WATERMARK_ID],
			[self::OPTION_MARK_WATERMARK_AT, self::OPTION_MARK_WATERMARK_ID],
			[self::OPTION_DELETION_WATERMARK_AT, self::OPTION_DELETION_WATERMARK_ID],
		];
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

	/**
	 * @return array{
	 *     rights: int,
	 *     marks: int,
	 *     deletions: int,
	 *     budgetSpent: bool,
	 *     contended: bool,
	 *     skipped: bool,
	 *     lagSeconds: int
	 * }
	 */
	private static function result(
		int $rights = 0,
		int $marks = 0,
		int $deletions = 0,
		bool $budgetSpent = false,
		bool $contended = false,
		bool $skipped = false,
		int $lag = 0,
	): array
	{
		return [
			'rights' => $rights,
			'marks' => $marks,
			'deletions' => $deletions,
			'budgetSpent' => $budgetSpent,
			'contended' => $contended,
			'skipped' => $skipped,
			'lagSeconds' => $lag,
		];
	}
}
