<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync;

use Bitrix\Disk\Internal\Repository\Sync\SyncAccessSnapshotRepository;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Internal\Service\Sync\Journal\AudienceResolver;
use Bitrix\Disk\Internal\Service\Sync\Journal\ChangeRecord;
use Bitrix\Disk\Internal\Service\Sync\Journal\JournalWriter;
use Bitrix\Disk\Internals\SimpleRightTable;
use Bitrix\Disk\Internals\StorageTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Psr\Log\LoggerInterface;

/**
 * Comparing the access codes a user holds now with the ones the pair was last told about.
 *
 * List visibility is served straight out of b_user_access, so a user dropped from a group keeps
 * receiving the files of that group until the codes are refreshed - and even once they are, the
 * client is never told that what it already holds has stopped being visible. This comparison is what
 * turns that silence into a signal.
 *
 * The asymmetry of the two outcomes is not an accident. A narrowing arrives as the single scope "all
 * access": it cannot be attached to a resource, because the object that stopped being visible is
 * exactly the one no query of this user returns any more. An expansion cannot be closed by that same
 * signal: reconciling by list only checks the objects the client already knows about and never finds
 * what has just appeared, and the scope "all access" carries no root to fetch. So the added codes are
 * unfolded into the roots they opened, and each of those roots is published as an ordinary change of
 * access with a scope of its own.
 *
 * The unfolding is coarsened to the storages the roots lie in, and that is what keeps the expansion
 * affordable. A code is written on every object that carries a readable right of its own, nested ones
 * included, so unfolding it one for one hands the client hundreds of areas to walk where the portal
 * measured 478 for a single code, well past the 378 the client would spend a whole refetch on. A
 * storage root covers with its subtree every one of those objects at once, so the client walks the
 * same objects once instead of once per nested area, and one expansion can never cost it more areas
 * than the number of storages it opened.
 */
final class AccessCodeSnapshotService
{
	private const LOGGER_CHANNEL = 'sync';

	/**
	 * How many checkpoints one statement carries when they are matched against the storages above them.
	 */
	private const IDS_PER_QUERY = 500;

	/**
	 * How many times the undoing of a refused transition is retried against a reader that wins the row
	 * of the pair in between.
	 */
	private const RESTORE_ATTEMPTS = 3;

	private SyncAccessSnapshotRepository $repository;
	private LoggerInterface $logger;
	private JournalWriter $journal;

	public function __construct(
		?SyncAccessSnapshotRepository $repository = null,
		?LoggerInterface $logger = null,
		?JournalWriter $journal = null,
	)
	{
		$this->repository = $repository ?? new SyncAccessSnapshotRepository();
		$this->logger = $logger ?? LoggerFactory::create(self::LOGGER_CHANNEL, feature: 'access code snapshot');
		$this->journal = $journal ?? new JournalWriter();
	}

	/**
	 * @param string[] $actualCodes the set the user holds now, already refreshed by the caller
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SqlQueryException
	 * @throws SystemException
	 */
	public function compareWithSnapshot(int $userId, string $appId, array $actualCodes): void
	{
		$snapshot = $this->repository->findByPair($userId, $appId);
		if ($snapshot === null)
		{
			// The first session of the pair has nothing to compare against: the baseline is laid down
			// and nothing is published, or every first client would start with a reconciliation.
			$this->repository->createIfAbsent($userId, $appId, $actualCodes);

			return;
		}

		if ($this->repository->hashCodes($actualCodes) === $snapshot['CODES_HASH'])
		{
			return;
		}

		// The transition on the previous revision is what keeps one divergence one signal. Two parallel
		// reads of the same user both see the narrowing; the one that loses the transition has nothing
		// left to report, and the client is not sent into a reconciliation of its whole memory twice.
		if (!$this->repository->updateIfRevisionMatches($userId, $appId, $snapshot['REVISION'], $actualCodes))
		{
			return;
		}

		$removed = array_values(array_diff($snapshot['CODES'], $actualCodes));
		$added = array_values(array_diff($actualCodes, $snapshot['CODES']));

		$published = true;

		if ($removed !== [])
		{
			$published = $this->publishAccessChanged($userId, null, $removed);
		}

		// Unfolding costs a query per code and is therefore done here, on the divergence itself, and
		// never on the calls that found the set unchanged.
		foreach ($added as $code)
		{
			foreach ($this->openedRootsOf($code) as $rootObjectId)
			{
				$published = $this->publishAccessChanged($userId, $rootObjectId, [$code]) && $published;
			}
		}

		if (!$published)
		{
			$this->takeSnapshotBack($userId, $appId, $snapshot, $actualCodes);
		}
	}

	/**
	 * The transition is undone, so that the divergence the journal refused to take is met again by the
	 * next call and published then.
	 *
	 * Without it the comparison is the one source of this class of change that has no second chance:
	 * the advanced snapshot matches the codes of the user, every later call leaves on the hash, and
	 * nothing else in the contour notices that a user was dropped from a group. The whole divergence
	 * goes back even when only one record of it was lost - a signal published twice costs the client
	 * one reconciliation it did not need, and a narrowing published never leaves it holding objects it
	 * may no longer see.
	 *
	 * What goes back is the delta of this call and not the row it read. A parallel reader of the same
	 * pair may have moved the snapshot on and published a divergence of its own by then, and writing
	 * the previous set over that would take away the baseline its publication was spent on, while a
	 * transition conditioned on the revision this call wrote would simply do nothing at all and leave
	 * the narrowing to nobody. So the codes this call took away are put back and the ones it added are
	 * taken out, on top of the row as it stands - which on a pair nobody else is reading is exactly the
	 * set the row held before.
	 *
	 * The way back is a transition of its own rather than the previous revision handed out again: the
	 * revision is what parallel readers hold their transitions on, and a number given out twice would
	 * let two of them win the same one. A reader that wins the row in between is met by another
	 * attempt, and a few of them are the whole of the contention one pair can raise here.
	 *
	 * @param array $snapshot the row as it stood before the transition
	 *        {@see SyncAccessSnapshotRepository::findByPair()}
	 * @param string[] $actualCodes the set the transition wrote
	 */
	private function takeSnapshotBack(int $userId, string $appId, array $snapshot, array $actualCodes): void
	{
		$takenAway = array_diff($snapshot['CODES'], $actualCodes);
		$added = array_diff($actualCodes, $snapshot['CODES']);

		$restored = false;
		for ($attempt = 0; $attempt < self::RESTORE_ATTEMPTS && !$restored; $attempt++)
		{
			$current = $this->repository->findByPair($userId, $appId);
			if ($current === null)
			{
				// The row was taken by the cleaning by age; the next call of the pair lays a baseline
				// of its own, and there is no set left for this divergence to be met against.
				break;
			}

			$restored = $this->repository->updateIfRevisionMatches(
				$userId,
				$appId,
				$current['REVISION'],
				array_values(array_diff(array_merge($current['CODES'], $takenAway), $added)),
			);
		}

		$this->logger->warning(
			'the journal refused a change of access of the pair, the transition was undone: {restored}',
			[
				'restored' => $restored ? 'yes' : 'no',
				'userId' => $userId,
				'appId' => $appId,
				'revision' => $snapshot['REVISION'],
			],
		);
	}

	/**
	 * The roots an access code opened, one per storage rather than one per checkpoint.
	 *
	 * Two checkpoints of one storage collapse into the single record of its root, and a checkpoint
	 * nested under another one stops publishing a record of its own: the area of the storage root
	 * already holds both.
	 *
	 * A checkpoint no storage root stands above keeps being a root of its own. Coarsening may only
	 * take areas away from the client, never the objects inside them, and a checkpoint left without a
	 * storage would otherwise be dropped from the expansion altogether.
	 *
	 * @return int[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function openedRootsOf(string $code): array
	{
		$roots = [];
		$checkpointIds = $this->checkpointsOf($code);
		$storageRootByCheckpointId = $this->storageRootsCovering($checkpointIds);
		foreach ($checkpointIds as $checkpointId)
		{
			$roots[$storageRootByCheckpointId[$checkpointId] ?? $checkpointId] = true;
		}

		return array_keys($roots);
	}

	/**
	 * The checkpoints an access code is written on: b_disk_simple_right holds a row for a checkpoint
	 * object only - the object that carries a readable right of its own - so the objects listed under
	 * a code are exactly the places the visibility it gives begins at.
	 *
	 * @return int[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function checkpointsOf(string $code): array
	{
		$rows = SimpleRightTable::query()
			->setSelect(['OBJECT_ID'])
			->where('ACCESS_CODE', $code)
			->setDistinct()
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['OBJECT_ID'], $rows);
	}

	/**
	 * The storage root standing above each of the checkpoints, by the tree of paths and not by the
	 * storage the object names.
	 *
	 * The difference matters. An area is unfolded through that same tree of paths, so a root found in
	 * it is a root whose subtree provably holds the checkpoint. The storage written on the object is
	 * an answer to a different question: a portal has already been seen where an object stopped
	 * inheriting the storage of its container, and following that column would name a root of another
	 * tree - one whose subtree does not hold the checkpoint at all, leaving the client with an area
	 * that never mentions what has just become visible.
	 *
	 * The row a checkpoint has on itself is part of the tree, so a checkpoint that is a storage root
	 * is its own answer here and the record about it stays exactly as it is today.
	 *
	 * @param int[] $checkpointIds
	 * @return array<int, int> checkpoint id => the root of the storage it lies in
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function storageRootsCovering(array $checkpointIds): array
	{
		$roots = [];
		foreach (array_chunk($checkpointIds, self::IDS_PER_QUERY) as $chunk)
		{
			$rows = StorageTable::query()
				->setSelect(['ROOT_OBJECT_ID'])
				->addSelect('ROOT_OBJECT.PATH_PARENT.OBJECT_ID', 'CHECKPOINT_ID')
				->whereIn('ROOT_OBJECT.PATH_PARENT.OBJECT_ID', $chunk)
				->fetchAll()
			;

			foreach ($rows as $row)
			{
				$roots[(int)$row['CHECKPOINT_ID']] = (int)$row['ROOT_OBJECT_ID'];
			}
		}

		return $roots;
	}

	/**
	 * The single point at which a divergence leaves this service.
	 *
	 * Both directions are addressed to the user personally and to nobody else. The codes of the
	 * divergence cannot carry the record themselves: on a narrowing the user no longer holds them, and
	 * on an expansion they are held by the whole group the user has just joined, for whom nothing has
	 * changed at all.
	 *
	 * @param int|null $rootObjectId the root of what became visible, or null for the scope "all
	 *        access" - the whole of what the user may see
	 * @param string[] $codes the codes the divergence consisted of: what the trace names, not what the
	 *        record is addressed to
	 * @return bool whether the journal took the record. False is what the snapshot is taken back on:
	 *        the advance of the snapshot is what spends the divergence, and spending it on a record
	 *        that was never written spends it for good.
	 */
	private function publishAccessChanged(int $userId, ?int $rootObjectId, array $codes): bool
	{
		$this->logger->info(
			'access of the pair changed: {scope}',
			[
				'scope' => $rootObjectId === null ? 'all access' : 'root ' . $rootObjectId,
				'userId' => $userId,
				'rootObjectId' => $rootObjectId,
				'codes' => $codes,
			],
		);

		return $this->journal->publishAccessChanged(
			$rootObjectId === null ? ChangeRecord::SCOPE_ALL_ACCESS : ChangeRecord::SCOPE_SUBTREE,
			$rootObjectId,
			$rootObjectId !== null,
			[],
			[AudienceResolver::personalCodeOf($userId)],
		);
	}
}
