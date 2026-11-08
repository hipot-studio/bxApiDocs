<?php

namespace Bitrix\StaffTrack\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Internal\Model\AddressQueueTable;
use Bitrix\StaffTrack\Internal\Model\CheckInByDayTable;
use Bitrix\StaffTrack\Internal\Model\CheckInTable;

class AddressQueueRepository
{
	/**
	 * How far back a point may be and still be answered for. Beyond it the walk has nothing to offer: the
	 * geo cleanup of a region may already have taken the coordinates out of the folded day, and the address
	 * of a point nobody can place is not worth keeping a row of the queue alive for. This is therefore both
	 * the reach of the lookup and the life of a queued row - see {@see self::resolveOrphansFromAggregates()}.
	 */
	private const ORPHAN_LOOKUP_DAYS = 30;

	private const CHUNK_SIZE = 500;

	/**
	 * Why a walk stopped before it had an answer for every point. The clock says the portal does not keep
	 * up with its own queue and is a matter for whoever runs it; it is the only guard left that can cut a
	 * walk, because a targeted lookup reads the day of a point and never a day chosen for it.
	 */
	public const STOPPED_BY_CLOCK = 'clock';

	/** Days whose geometry travels in one query, as in CheckInRepository: no read buffers more than a few. */
	private const ORPHAN_READ_ROWS = 4;

	/**
	 * Stores which point waits for whose answer and when the point was taken. The cell key is deliberately
	 * not stored: it is derived from the place somebody stood in, and the geo cleanup takes coordinates
	 * away but knows nothing of a key beside them. The drainer derives the cell in memory from the
	 * coordinates it reads anyway.
	 *
	 * The date of the point is stored, and for the opposite reason: it is the one thing about a point that
	 * cannot be derived later. A queued point outlives its own raw row - the aggregation folds the day and
	 * deletes it - and from that moment the date is the only way to name the folded day its coordinates
	 * ended up in, and the only measure of how long the row has left.
	 *
	 * @param array<int, array{checkInId: int, userId: int, pointDate: int}> $points pointDate is unix time
	 * @return int rows actually stored, duplicates of the primary key excluded
	 */
	public static function addMany(array $points, int $viewerId = 0): int
	{
		if (empty($points))
		{
			return 0;
		}

		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$table = $sqlHelper->quote(AddressQueueTable::getTableName());
		$columns = ' (' . $sqlHelper->quote('CHECK_IN_ID')
			. ', ' . $sqlHelper->quote('VIEWER_ID')
			. ', ' . $sqlHelper->quote('USER_ID')
			. ', ' . $sqlHelper->quote('POINT_DATE') . ') '
		;

		$stored = 0;

		foreach (array_chunk(array_values($points), self::CHUNK_SIZE) as $chunk)
		{
			$values = [];
			foreach ($chunk as $point)
			{
				$checkInId = (int)($point['checkInId'] ?? 0);
				$userId = (int)($point['userId'] ?? 0);
				$pointDate = (int)($point['pointDate'] ?? 0);
				if ($checkInId <= 0 || $userId <= 0 || $pointDate <= 0)
				{
					continue;
				}

				// through the helper and not as a literal: the column is a datetime and the two supported
				// databases spell one differently
				$values[] = '(' . $checkInId . ', ' . $viewerId . ', ' . $userId . ', '
					. $sqlHelper->convertToDbDateTime(DateTime::createFromTimestamp($pointDate)) . ')'
				;
			}

			if (empty($values))
			{
				continue;
			}

			$connection->queryExecute(
				$sqlHelper->getInsertIgnore($table, $columns, ' VALUES ' . implode(', ', $values)),
			);

			$stored += max(0, (int)$connection->getAffectedRowsCount());
		}

		return $stored;
	}

	public static function hasRows(): bool
	{
		return AddressQueueTable::getRow(['select' => ['CHECK_IN_ID']]) !== null;
	}

	/**
	 * @param int[] $checkInIds
	 */
	public static function hasRowsByCheckInIds(array $checkInIds): bool
	{
		foreach (self::chunkIds($checkInIds) as $chunk)
		{
			$row = AddressQueueTable::getRow([
				'select' => ['CHECK_IN_ID'],
				'filter' => ['@CHECK_IN_ID' => $chunk],
			]);

			if ($row !== null)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The order is by CHECK_IN_ID so that a batch that could not be drained in one pass moves forward
	 * instead of meeting the same rows again.
	 *
	 * The seek is what makes that true for a batch nothing could be done with. A pass that resolves and
	 * deletes nothing leaves the queue exactly as it found it, and without a seek the next one would take
	 * the same fifty rows and spend the same reading on them for as long as the queue lives. The caller
	 * therefore carries the last id past such a batch and drops the seek as soon as anything moves.
	 *
	 * @param int $afterCheckInId rows above this id only; zero starts at the head of the queue
	 * @return array<int, array{CHECK_IN_ID: int, VIEWER_ID: int, USER_ID: int, POINT_DATE: int}>
	 */
	public static function getBatch(int $limit, int $afterCheckInId = 0): array
	{
		if ($limit <= 0)
		{
			return [];
		}

		$query = AddressQueueTable::query()
			->setSelect(['CHECK_IN_ID', 'VIEWER_ID', 'USER_ID', 'POINT_DATE'])
			->setOrder(['CHECK_IN_ID' => 'ASC'])
			->setLimit($limit)
		;

		if ($afterCheckInId > 0)
		{
			$query->where('CHECK_IN_ID', '>', $afterCheckInId);
		}

		$rows = $query->exec()->fetchAll();

		return array_map(
			static fn(array $row): array => [
				'CHECK_IN_ID' => (int)$row['CHECK_IN_ID'],
				'VIEWER_ID' => (int)$row['VIEWER_ID'],
				'USER_ID' => (int)$row['USER_ID'],
				'POINT_DATE' => self::timestampOf($row['POINT_DATE'] ?? null),
			],
			$rows,
		);
	}

	/**
	 * Every row of the check-ins, whoever it is queued for. Called where the check-in itself is done with -
	 * the point is gone, its day is cleaned - and never to close a batch: a batch is bounded by rows and
	 * can hold some of the viewers of a check-in and not the others (see {@see self::deleteRows()}).
	 *
	 * @param int[] $checkInIds
	 */
	public static function deleteByCheckInIds(array $checkInIds): void
	{
		foreach (self::chunkIds($checkInIds) as $chunk)
		{
			AddressQueueTable::deleteByFilter(['@CHECK_IN_ID' => $chunk]);
		}
	}

	/**
	 * Rows of the queue by their own key. A check-in stands in the queue once per viewer and a batch is
	 * bounded by rows, so deleting by CHECK_IN_ID alone would drop the viewers left outside the batch and
	 * they would never be pushed the address.
	 *
	 * @param array<int, array{CHECK_IN_ID: int, VIEWER_ID: int}> $rows
	 */
	public static function deleteRows(array $rows): void
	{
		$checkInIdsByViewer = [];
		foreach ($rows as $row)
		{
			$checkInId = (int)($row['CHECK_IN_ID'] ?? 0);
			if ($checkInId <= 0)
			{
				continue;
			}

			$checkInIdsByViewer[(int)($row['VIEWER_ID'] ?? 0)][] = $checkInId;
		}

		foreach ($checkInIdsByViewer as $viewerId => $checkInIds)
		{
			foreach (self::chunkIds($checkInIds) as $chunk)
			{
				AddressQueueTable::deleteByFilter(['=VIEWER_ID' => $viewerId, '@CHECK_IN_ID' => $chunk]);
			}
		}
	}

	/**
	 * Coordinates of the queued points. The raw row of a point older than the aggregation window is gone,
	 * and its coordinates live inside the day of its author, so the aggregated history answers for those.
	 *
	 * The answer carries two different things, and a caller that confuses them loses data: `coords` are the
	 * coordinates found, `undecided` are the points nothing was decided about - the walk did not reach the
	 * day holding them, or that day is not in the storage yet. A point of the second kind may still have
	 * its coordinates - answering "no coordinates" for it costs it its address for good, because the
	 * drainer takes such rows off the queue.
	 *
	 * Everything else missing from `coords` is a verdict and a final one: either the day of the point was
	 * read and does not hold it, or the point is older than the lookup can reach at all.
	 *
	 * The deadline is the share of the pass this lookup may take. The caller owns the clock - the lookup is
	 * one phase of a pass that also calls the geo service - and the lookup only obeys it, turning what it
	 * did not reach into `undecided`.
	 *
	 * @param array<int, array{userId: int, pointDate: int}> $pointsByCheckInId checkInId => point
	 * @param int $deadline unix time the walk must stop at; zero leaves it unbounded
	 * @return array{
	 *     coords: array<int, array{LATITUDE: ?string, LONGITUDE: ?string}>,
	 *     undecided: array<int, true>,
	 *     stoppedBy: ?string
	 * } `stoppedBy` names what cut the walk short, STOPPED_BY_*, and is null when nothing did
	 */
	public static function getCoordsByCheckInIds(array $pointsByCheckInId, int $deadline = 0): array
	{
		if (empty($pointsByCheckInId))
		{
			return ['coords' => [], 'undecided' => [], 'stoppedBy' => null];
		}

		$coordsById = [];

		foreach (self::chunkIds(array_keys($pointsByCheckInId)) as $chunk)
		{
			$coordsRows = CheckInTable::query()
				->setSelect(['ID', 'LATITUDE', 'LONGITUDE'])
				->whereIn('ID', $chunk)
				->exec()
				->fetchAll()
			;

			foreach ($coordsRows as $coord)
			{
				$coordsById[(int)$coord['ID']] = [
					'LATITUDE' => $coord['LATITUDE'],
					'LONGITUDE' => $coord['LONGITUDE'],
				];
			}
		}

		$missing = [];
		foreach ($pointsByCheckInId as $checkInId => $point)
		{
			if (!isset($coordsById[(int)$checkInId]))
			{
				$missing[(int)$checkInId] = $point;
			}
		}

		if (empty($missing))
		{
			return ['coords' => $coordsById, 'undecided' => [], 'stoppedBy' => null];
		}

		$fromAggregates = self::resolveOrphansFromAggregates($missing, $deadline);

		return [
			'coords' => $coordsById + $fromAggregates['resolved'],
			'undecided' => $fromAggregates['undecided'],
			'stoppedBy' => $fromAggregates['stoppedBy'],
		];
	}

	/**
	 * The day a point belongs to is named by the point itself - by the date it was taken - and that day is
	 * the only one read for it. A walk that instead guessed at the newest days of an author could never
	 * answer for a point whose day was not among them, and a point it did not answer for kept its row in
	 * the queue for good: nothing ages a row, and no repeat changes what the storage holds.
	 *
	 * Being able to say "not there" is what ends a row. Two verdicts are final and both come from here:
	 * the day of the point was read and does not hold it, and the point is older than the window the
	 * lookup reaches at all. The second one is the life of a queued row, and it costs no reading: candidate
	 * days were never taken from beyond the window even when the walk was untargeted, so a point older than
	 * that was already unanswerable - it simply had no way of being told so.
	 *
	 * What stays `undecided` keeps its row: a day the clock did not let the walk reach says nothing about
	 * the points inside it, and a point whose day is not in the storage yet may still be folded into one.
	 * Their rows wait, and their age ends them if nothing else does.
	 *
	 * @param array<int, array{userId: int, pointDate: int}> $missing checkInId => point
	 * @param int $deadline unix time the walk must stop at; zero leaves it unbounded
	 * @return array{
	 *     resolved: array<int, array{LATITUDE: ?string, LONGITUDE: ?string}>,
	 *     undecided: array<int, true>,
	 *     stoppedBy: ?string
	 * }
	 */
	private static function resolveOrphansFromAggregates(array $missing, int $deadline = 0): array
	{
		$horizon = time() - self::ORPHAN_LOOKUP_DAYS * 86400;

		$fresh = [];
		foreach ($missing as $checkInId => $point)
		{
			if ((int)($point['pointDate'] ?? 0) >= $horizon)
			{
				$fresh[(int)$checkInId] = $point;
			}
		}

		if (empty($fresh))
		{
			return ['resolved' => [], 'undecided' => [], 'stoppedBy' => null];
		}

		[$pointsByDay, $dayIds, $undecided] = self::coveringDays($fresh);

		$resolved = [];
		$stoppedBy = null;

		foreach (array_chunk($dayIds, self::ORPHAN_READ_ROWS) as $page)
		{
			if ($deadline > 0 && time() >= $deadline)
			{
				$stoppedBy = self::STOPPED_BY_CLOCK;

				break;
			}

			$days = CheckInByDayTable::query()
				->setSelect(['ID', 'GEO_DATA'])
				->whereIn('ID', $page)
				->exec()
			;

			// Streamed and not fetched whole: a day of the new geometry weighs about a megabyte, and no day
			// outlives the iteration that reads it - the walk holds one of them, never the sum.
			while ($day = $days->fetch())
			{
				$dayId = (int)$day['ID'];
				$needed = $pointsByDay[$dayId] ?? [];

				// Struck off whatever the day held: the ones found are resolved, the ones not found have
				// their verdict from the one day that could have held them.
				unset($pointsByDay[$dayId]);

				if (empty($needed))
				{
					continue;
				}

				self::takeCoordsFromDay((string)($day['GEO_DATA'] ?? ''), $needed, $resolved);
			}
		}

		foreach ($pointsByDay as $needed)
		{
			foreach (array_keys($needed) as $checkInId)
			{
				$undecided[(int)$checkInId] = true;
			}
		}

		return ['resolved' => $resolved, 'undecided' => $undecided, 'stoppedBy' => $stoppedBy];
	}

	/**
	 * Which folded day holds which point. A day carries the window of its own geometry - DATE_CREATE is the
	 * first point of it and DATE_END the last, both widened as points are added - so the day of a point is
	 * the one whose window covers the date of the point.
	 *
	 * One lookup per employee and not one for all of them: the window of a batch is the span of the dates
	 * of that employee alone, and a shared span would drag in the days of everybody in between. The select
	 * carries no GEO_DATA on purpose - a day weighs about a megabyte, and placing a point in a day needs
	 * the window, not the geometry, which is read afterwards a few days at a time.
	 *
	 * The days are handed back breadth first, the first day of every employee before the second of any of
	 * them. The clock of the caller can cut the reading at any page, and an employee it never reached would
	 * leave every point of theirs waiting for another pass.
	 *
	 * @param array<int, array{userId: int, pointDate: int}> $points checkInId => point
	 * A point that several windows cover stands under each of them: the day rows of one day are normally
	 * one, and where they are not, reading only one of them would answer for a point out of a row that
	 * never held it. Whichever row holds the point answers for it, and the rest strike it off having found
	 * nothing - which changes nothing, the answer is already there.
	 *
	 * @return array{0: array<int, array<int, int>>, 1: int[], 2: array<int, true>} points of every day,
	 *         the day ids to read in order, and the points no day of the storage covers
	 */
	private static function coveringDays(array $points): array
	{
		$datesByUser = [];
		foreach ($points as $checkInId => $point)
		{
			$datesByUser[(int)$point['userId']][(int)$checkInId] = (int)$point['pointDate'];
		}

		$pointsByDay = [];
		$daysByUser = [];
		$uncovered = [];

		$windowsByUser = self::dayWindowsOfUsers($datesByUser);

		foreach ($datesByUser as $userId => $dates)
		{
			$windows = $windowsByUser[$userId] ?? [];

			$ownDays = [];
			foreach ($dates as $checkInId => $pointDate)
			{
				$dayIds = self::daysCovering($windows, $pointDate);
				if (empty($dayIds))
				{
					$uncovered[$checkInId] = true;

					continue;
				}

				foreach ($dayIds as $dayId)
				{
					$pointsByDay[$dayId][$checkInId] = $pointDate;
					$ownDays[$dayId] = true;
				}
			}

			if (!empty($ownDays))
			{
				$daysByUser[] = array_keys($ownDays);
			}
		}

		$ids = [];
		for ($depth = 0; ; $depth++)
		{
			$taken = false;
			foreach ($daysByUser as $days)
			{
				if (isset($days[$depth]))
				{
					$ids[] = $days[$depth];
					$taken = true;
				}
			}

			if (!$taken)
			{
				break;
			}
		}

		return [$pointsByDay, $ids, $uncovered];
	}

	/**
	 * One read for the whole batch of the pass, not one per employee. A batch carries the points of as many
	 * employees as the readings that filled the queue, and the days of each used to be asked for by a query
	 * of their own - fifty points of fifty employees cost fifty round trips before a single row of the
	 * folded days was touched.
	 *
	 * The rows come back for the range that covers every employee at once and are cut to the range of their
	 * own afterwards: the common range is the wider one, and a day nothing of that employee can reach must
	 * not become a day to read. The cut repeats the condition the per-employee query carried, so the days
	 * of an employee are the same days as before, in the same order.
	 *
	 * @param array<int, array<int, int>> $datesByUser userId => (checkInId => point date)
	 * @return array<int, array<int, array{0: int, 1: int}>> userId => (dayId => window start and end)
	 */
	private static function dayWindowsOfUsers(array $datesByUser): array
	{
		if (empty($datesByUser))
		{
			return [];
		}

		$from = null;
		$to = null;
		foreach ($datesByUser as $dates)
		{
			$from = $from === null ? min($dates) : min($from, min($dates));
			$to = $to === null ? max($dates) : max($to, max($dates));
		}

		$rows = CheckInByDayTable::query()
			->setSelect(['ID', 'USER_ID', 'DATE_CREATE', 'DATE_END'])
			->whereIn('USER_ID', array_keys($datesByUser))
			->where('DATE_CREATE', '<=', DateTime::createFromTimestamp($to))
			->where('DATE_END', '>=', DateTime::createFromTimestamp($from))
			->setOrder(['DATE_CREATE' => 'ASC'])
			->exec()
			->fetchAll()
		;

		$windows = [];
		foreach ($rows as $row)
		{
			$userId = (int)$row['USER_ID'];
			$dates = $datesByUser[$userId] ?? null;
			if ($dates === null)
			{
				continue;
			}

			$start = self::timestampOf($row['DATE_CREATE'] ?? null);
			$end = self::timestampOf($row['DATE_END'] ?? null);

			if ($start > max($dates) || $end < min($dates))
			{
				continue;
			}

			$windows[$userId][(int)$row['ID']] = [$start, $end];
		}

		return $windows;
	}

	/**
	 * Every day whose window covers the date and not merely the first of them. One day of one employee is
	 * one row, so normally there is exactly one - but the row is created by whoever folds the day first,
	 * and two of them racing leave two rows over the same hours. Taking only the first would look for the
	 * point in the row that does not hold it and call that a verdict, which costs the point its address.
	 *
	 * @param array<int, array{0: int, 1: int}> $windows
	 * @return int[]
	 */
	private static function daysCovering(array $windows, int $pointDate): array
	{
		$dayIds = [];

		foreach ($windows as $dayId => [$from, $to])
		{
			if ($pointDate >= $from && $pointDate <= $to)
			{
				$dayIds[] = $dayId;
			}
		}

		return $dayIds;
	}

	/**
	 * Takes the coordinates of the points of this day out of its geometry. The points it does not find are
	 * left to the caller, which has their verdict: this is the day their date named, and it does not hold
	 * them.
	 *
	 * @param array<int, int> $needed checkInId => point date
	 * @param array<int, array{LATITUDE: ?string, LONGITUDE: ?string}> $resolved
	 */
	private static function takeCoordsFromDay(string $geoDataJson, array $needed, array &$resolved): void
	{
		$geo = json_decode($geoDataJson, true);
		if (!is_array($geo))
		{
			return;
		}

		foreach ($geo as $point)
		{
			$pointId = (int)($point['ID'] ?? 0);
			if (!isset($needed[$pointId]))
			{
				continue;
			}

			$resolved[$pointId] = [
				'LATITUDE' => $point['LATITUDE'] ?? null,
				'LONGITUDE' => $point['LONGITUDE'] ?? null,
			];

			unset($needed[$pointId]);
			if (empty($needed))
			{
				return;
			}
		}
	}

	/**
	 * The ORM answers a datetime column with an object and a raw read with a string; both reach this class.
	 */
	private static function timestampOf(mixed $value): int
	{
		if ($value instanceof DateTime)
		{
			return $value->getTimestamp();
		}

		if (is_string($value) && $value !== '')
		{
			return (int)strtotime($value);
		}

		return (int)$value;
	}

	/**
	 * @param int[] $checkInIds
	 * @return array<int, int[]>
	 */
	private static function chunkIds(array $checkInIds): array
	{
		$checkInIds = array_filter(array_map('intval', $checkInIds));
		if (empty($checkInIds))
		{
			return [];
		}

		return array_chunk(array_unique($checkInIds), self::CHUNK_SIZE);
	}
}
