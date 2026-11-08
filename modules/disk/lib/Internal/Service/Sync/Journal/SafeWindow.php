<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Journal;

use Bitrix\Disk\Internal\Model\SyncLogTable;
use Bitrix\Disk\Internal\Repository\Sync\SyncLogRepository;
use Bitrix\Disk\Internal\Service\Sync\SyncLimits;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * How far down the tail of the journal a reader may go.
 *
 * A record gets its identifier when it is inserted and becomes visible when the transaction around it
 * commits, and the two moments are not the same one. Disk publishes from inside the mutations of other
 * modules, whose transactions have no bound on their length - deleting a list is one transaction over
 * the whole information block - so a record can be handed a low identifier and appear minutes later.
 * A reader whose cursor stepped over that identifier in the meantime would never come back for it.
 *
 * That is why the tail is held back by the gaps in the sequence rather than by a width of time. A
 * record still inside an open transaction shows up as a hole - 100 and 102 are there, 101 is not - and
 * the watermark stays under the lowest hole until it closes. In the usual case there are no holes and
 * the whole journal is served at once, which a window of time would never do: it would delay every
 * reader by its own width whether anything was in flight or not.
 *
 * A hole is not always going to close: a transaction that rolled back leaves one behind for good, and
 * so does the cleaning of the journal by its retention. Nothing distinguishes those from a hole still
 * being written, so they are told apart by age instead: a hole is only waited for while the record
 * above it is younger than {@see SyncLimits::getSafeWindowSeconds()}. That is the meaning the contour
 * publishes as safeWindowSeconds - not "every change is delayed by this much" but "a change may be
 * held back by no more than this much". Once the ceiling is passed the hole is stepped over, which
 * costs at most the one record still inside a transaction that outlived the ceiling; waiting instead
 * would stop the journal of every client on the portal for as long as that transaction runs.
 *
 * A record whose codes are not attached yet is a hole of the same kind and the one the journal makes
 * itself: a record is written first and the codes addressing it after it, in a statement of their own,
 * so between the two it stands in the sequence addressed to nobody. A reader finds nothing in such a
 * record, takes the whole read for empty and moves its position to the watermark - past a record it
 * would have been handed a moment later. So the tail is held under one of those as well, and let go of
 * it by the same ceiling: an addressing that failed for good holds the watermark back by the width of
 * the window and is stepped over after it.
 *
 * The age of a hole is read off the first record above it, because that record was written after the
 * identifier of the hole had already been handed out. The clock is the one of the database for the
 * same reason the journal stamps its records there: the nodes writing and reading disagree about the
 * time, and the boundary has to be comparable with the column.
 *
 * The same question is asked once more from the other end: where a client that has just read the
 * state of the portal picks the journal up, so that the two overlap by the width of the window rather
 * than by the whole retention. That is {@see SafeWindow::maxPublishedIdReflectedAt()}.
 */
final class SafeWindow
{
	/**
	 * How many records of the young tail one answer walks.
	 *
	 * The tail is what the portal wrote within the ceiling, so its length follows the write rate and
	 * not the distance a client has fallen behind. The bound is what keeps a burst of writing from
	 * turning the answer into a scan; stopping early only lowers the watermark, which costs a reader
	 * one more page and never a record.
	 */
	private const YOUNG_TAIL_PROBE = 1000;

	private SyncLimits $limits;
	private SyncLogRepository $repository;

	public function __construct(?SyncLimits $limits = null, ?SyncLogRepository $repository = null)
	{
		$this->limits = $limits ?? new SyncLimits();
		$this->repository = $repository ?? new SyncLogRepository();
	}

	/**
	 * The greatest identifier every record up to which is settled: either written, visible and
	 * addressed, or absent for good.
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SqlQueryException
	 * @throws SystemException
	 */
	public function maxPublishedId(): int
	{
		$youngIds = $this->readYoungTail();
		if ($youngIds === [])
		{
			// Nothing was written within the ceiling, so nothing can still be on its way in.
			return $this->maxId();
		}

		$watermark = $this->maxIdBelow($youngIds[0]);
		$unbroken = $this->unbrokenRunAbove($youngIds, $watermark);
		$addressed = array_flip($this->repository->findAddressedAmong($unbroken));

		foreach ($youngIds as $id)
		{
			if ($id !== $watermark + 1 || !isset($addressed[$id]))
			{
				break;
			}

			$watermark = $id;
		}

		return $watermark;
	}

	/**
	 * The records of the tail standing one after another above the given identifier. The walk stops at
	 * the first hole whatever else it finds, so they are the only ones whose addressing is worth asking
	 * about.
	 *
	 * @param int[] $youngIds
	 * @return int[]
	 */
	private function unbrokenRunAbove(array $youngIds, int $settled): array
	{
		$run = [];
		foreach ($youngIds as $id)
		{
			if ($id !== $settled + 1)
			{
				break;
			}

			$run[] = $id;
			$settled = $id;
		}

		return $run;
	}

	/**
	 * The greatest identifier a read of the state bounded at the given moment has certainly already
	 * taken in. It is where the journal is picked up from after such a read, so that the two overlap
	 * instead of leaving a gap between them.
	 *
	 * The moment is stepped back by the window a second time before the journal is asked. The first
	 * step is what the boundary of the state already carries; the second one is the disagreement of
	 * two clocks: the row of an object is stamped by php on the node performing the mutation and the
	 * record about it by the database, and the two are compared here against each other. The width of
	 * the window is far above any drift a portal keeps its nodes within - the same disagreement in the
	 * other direction is answered for by {@see SyncLimits::CLOCK_TOLERANCE_SECONDS} and is a minute
	 * wide.
	 *
	 * The position stops under the first record written since that ceiling rather than on the greatest
	 * one written before it: a record can be handed a low identifier and stamped later, and standing
	 * on the greatest identifier below the ceiling would step over it. Being wrong towards an earlier
	 * position costs the reader records it has already applied - applying them again changes nothing -
	 * and being wrong towards a later one loses a change silently.
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SqlQueryException
	 * @throws SystemException
	 */
	public function maxPublishedIdReflectedAt(DateTime $stateBoundary): int
	{
		$ceiling = DateTime::createFromTimestamp(
			$stateBoundary->getTimestamp() - $this->limits->getSafeWindowSeconds(),
		);

		$firstAbove = $this->repository->findFirstIdCreatedSince($ceiling);
		if ($firstAbove === null)
		{
			// The whole journal was written before the ceiling, so the whole of it is taken in.
			return $this->maxId();
		}

		return $this->maxIdBelow($firstAbove);
	}

	/**
	 * The records written within the ceiling, by identifier. Only they can be sitting above a hole
	 * that is still worth waiting for.
	 *
	 * @return int[]
	 * @throws SqlQueryException
	 */
	private function readYoungTail(): array
	{
		$connection = Application::getConnection();
		$ceiling = $connection->getSqlHelper()->addSecondsToDateTime(-$this->limits->getSafeWindowSeconds());

		$sql = (new SqlExpression(
			'SELECT ID FROM ?# WHERE CREATED_AT > ' . $ceiling . ' ORDER BY ID ASC',
			SyncLogTable::getTableName(),
		))->compile();

		$ids = [];
		$cursor = $connection->query($sql, self::YOUNG_TAIL_PROBE);
		while ($row = $cursor->fetch())
		{
			$ids[] = (int)$row['ID'];
		}

		return $ids;
	}

	/**
	 * @throws SqlQueryException
	 */
	private function maxIdBelow(int $id): int
	{
		$connection = Application::getConnection();

		$sql = (new SqlExpression('SELECT MAX(ID) FROM ?# WHERE ID < ?i', SyncLogTable::getTableName(), $id))
			->compile()
		;

		return (int)$connection->queryScalar($sql);
	}

	/**
	 * @throws SqlQueryException
	 */
	private function maxId(): int
	{
		$connection = Application::getConnection();

		$sql = (new SqlExpression('SELECT MAX(ID) FROM ?#', SyncLogTable::getTableName()))->compile();

		return (int)$connection->queryScalar($sql);
	}
}
