<?php

namespace Bitrix\StaffTrack\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Internal\Entity\StayEvent;
use Bitrix\StaffTrack\Internal\Model\CheckInTable;
use Bitrix\StaffTrack\Internal\Model\CheckInByDayTable;
use Bitrix\StaffTrack\Internal\Model\CheckInCollection;
use Bitrix\StaffTrack\Internal\Model\CheckInDailyStatsTable;

class CheckInRepository
{
	private const DAY_FORMAT = 'Y-m-d';

	/**
	 * How far a row of the route of a day may reach from its start to its end. CheckInAggregator::groupByDate()
	 * keeps a row within one local day; twice that is taken here, because a portal whose timezone changed
	 * between two aggregations leaves a wider row, and a row wrongly left out costs a route of a day its end.
	 */
	private const AGGREGATED_DAY_MAX_LENGTH_SEC = 172800;

	/**
	 * The bound the slice around the moments reads the aggregated days under. The weight is the one LENGTH()
	 * reports - the value as it is stored, encrypted and base64 encoded, some 275-299 bytes a point - so a
	 * dense day of about 4000 points weighs a little over a megabyte and the budget lets three or four of
	 * them through. Not the 3.3 KB a point costs in the memory of PHP: that is the weight of a day decoded,
	 * and no decoded day outlives the iteration that reads it. The slice is taken on the write path of a
	 * batch, under the lock of the employee, so past either bound it drops its heaviest tail rather than
	 * lengthening the hold. The rows are capped as well as weighed, for the days of a handful of points.
	 */
	private const AROUND_AGGREGATED_BYTES = 4194304;
	private const AROUND_AGGREGATED_MAX_ROWS = 64;

	// Days whose geometry travels in one query. Small on purpose: the whole point of the budget is that
	// no single read buffers more than a few heavy days.
	private const AROUND_AGGREGATED_READ_ROWS = 4;

	private const ALL_FIELDS = [
		'ID',
		'USER_ID',
		'DATE_CREATE',
		'ENTITY_TYPE',
		'LATITUDE',
		'LONGITUDE',
		'DESCRIPTION',
		'ADDRESS',
		'MESSAGE_IDS',
		'USER_TIMEZONE',
		'FILE_IDS',
	];

	private const ENTITY_TYPE = [
		'MANUAL' => 1,
		'AUTOMATIC' => 2,
	];

	private const ROUTE_FIELDS = [
		'ID',
		'USER_ID',
		'DATE_CREATE',
		'ENTITY_TYPE',
		'LATITUDE',
		'LONGITUDE',
		'DESCRIPTION',
		'ADDRESS',
		'USER_TIMEZONE',
	];

	/**
	 * Everything the markers of a day are built from. MESSAGE_IDS is left out: no marker reads it, and
	 * it is a JsonField decoded on every row raised.
	 */
	private const MARKER_FIELDS = [
		'ID',
		'USER_ID',
		'DATE_CREATE',
		'ENTITY_TYPE',
		'LATITUDE',
		'LONGITUDE',
		'DESCRIPTION',
		'ADDRESS',
		'USER_TIMEZONE',
		'FILE_IDS',
	];

	/** The natural key of a point plus the id its repeat is answered with. */
	private const DEDUP_FIELDS = [
		'ID',
		'DATE_CREATE',
		'ENTITY_TYPE',
		'DESCRIPTION',
		'LATITUDE',
		'LONGITUDE',
	];

	/**
	 * @param int $userId
	 * @param DateTime $dateStart
	 * @param DateTime $dateEnd
	 * @return CheckInCollection
	 */
	public function getUserCheckInByPeriod(int $userId, DateTime $dateStart, DateTime $dateEnd): CheckInCollection
	{
		return CheckInTable::query()
			->setSelect(self::ALL_FIELDS)
			->where('USER_ID', $userId)
			->whereBetween('DATE_CREATE', $dateStart, $dateEnd)
			->setOrder(['DATE_CREATE' => 'ASC'])
			->fetchCollection()
		;
	}

	public function getUserManualCheckInByPeriod(int $userId, DateTime $dateStart, DateTime $dateEnd): CheckInCollection
	{
		return CheckInTable::query()
			->setSelect(self::ALL_FIELDS)
			->where('USER_ID', $userId)
			->where('ENTITY_TYPE', self::ENTITY_TYPE['MANUAL'])
			->whereBetween('DATE_CREATE', $dateStart, $dateEnd)
			->setOrder(['DATE_CREATE' => 'ASC'])
			->fetchCollection()
		;
	}

	/**
	 * Rows a batch of the employee could be a repeat of, raised by the exact timestamps of that batch
	 * rather than by their range: a catch-up batch spans hours, and a range would raise the whole day.
	 *
	 * @param int $userId
	 * @param DateTime[] $dates distinct timestamps of the batch, at most AddBatch::MAX_BATCH_SIZE of them
	 * @return array
	 */
	public function findPointsForDedup(int $userId, array $dates): array
	{
		if (empty($dates))
		{
			return [];
		}

		return CheckInTable::query()
			->setSelect(self::DEDUP_FIELDS)
			->where('USER_ID', $userId)
			->whereIn('DATE_CREATE', $dates)
			->exec()
			->fetchAll()
		;
	}

	/**
	 * Automatic points of the employee already written within the period. Counted over the raw rows: the
	 * daily-stats counter counts markers and leaves the technical movement points out on purpose.
	 *
	 * @param int $userId
	 * @param DateTime $dateStart
	 * @param DateTime $dateEnd
	 * @return int
	 */
	public function countAutomaticByPeriod(int $userId, DateTime $dateStart, DateTime $dateEnd): int
	{
		$row = CheckInTable::query()
			->addSelect(Query::expr()->count('ID'), 'CNT')
			->where('USER_ID', $userId)
			->where('ENTITY_TYPE', self::ENTITY_TYPE['AUTOMATIC'])
			->whereBetween('DATE_CREATE', $dateStart, $dateEnd)
			->exec()
			->fetch()
		;

		return (int)($row['CNT'] ?? 0);
	}

	/**
	 * A slice of the timeline of automatic points around the given moments: the last point at or before
	 * every moment and the first one after it, within the reach. Raw rows and already aggregated routes are
	 * read alike - past the aggregation delay the raw row is gone, and the raw table alone would show a
	 * track that ends there as a track that has none. Descriptions come back after Emoji::encode().
	 *
	 * @param int[] $timestamps moments to look around
	 * @param int $reachSec how far to look on either side of a moment
	 * @return array<int, array{timestamp: int, description: ?string}> ascending in time, the points
	 *         sharing a time in the order they were written
	 */
	public function getAutomaticPointsAround(int $userId, array $timestamps, int $reachSec): array
	{
		$moments = array_values(array_unique(array_map('intval', $timestamps)));
		if (empty($moments) || $reachSec < 0)
		{
			return [];
		}

		$spans = self::mergeSpans($moments, $reachSec);
		$neighbours = [];

		$rows = CheckInTable::query()
			->setSelect(['ID', 'DATE_CREATE', 'DESCRIPTION'])
			->where('USER_ID', $userId)
			->where('ENTITY_TYPE', self::ENTITY_TYPE['AUTOMATIC'])
			->where(self::rawSpansFilter($spans))
			->exec()
		;

		while ($row = $rows->fetch())
		{
			self::keepIfNeighbour($moments, $reachSec, $neighbours, [
				'timestamp' => $row['DATE_CREATE'] instanceof DateTime ? $row['DATE_CREATE']->getTimestamp() : 0,
				'id' => (int)$row['ID'],
				'description' => $row['DESCRIPTION'] === null ? null : (string)$row['DESCRIPTION'],
			]);
		}

		foreach (array_chunk(self::aggregatedIdsWithinBudget($userId, $spans), self::AROUND_AGGREGATED_READ_ROWS) as $ids)
		{
			$routes = CheckInByDayTable::query()
				->setSelect(['ID', 'GEO_DATA'])
				->whereIn('ID', $ids)
				->exec()
			;

			while ($route = $routes->fetch())
			{
				// The decoded day lives no longer than this iteration: keeping the days of the whole slice
				// would put megabytes of a dense route into memory for the sake of a handful of points.
				$geoData = json_decode((string)($route['GEO_DATA'] ?? ''), true);
				if (!is_array($geoData))
				{
					continue;
				}

				foreach ($geoData as $point)
				{
					if ((int)($point['ENTITY_TYPE'] ?? 0) !== self::ENTITY_TYPE['AUTOMATIC'])
					{
						continue;
					}

					self::keepIfNeighbour($moments, $reachSec, $neighbours, [
						'timestamp' => (int)($point['DATE_CREATE'] ?? 0),
						'id' => (int)($point['ID'] ?? 0),
						'description' => isset($point['DESCRIPTION']) ? (string)$point['DESCRIPTION'] : null,
					]);
				}
			}
		}

		return self::asTimeline($neighbours);
	}

	/**
	 * Keeps the point if it stands closer to one of the moments than what is held for it already: the last
	 * point at or before the moment, and the first one after it.
	 *
	 * @param int[] $moments
	 * @param array<int, array<string, array{timestamp: int, id: int, description: ?string}>> $neighbours
	 * @param array{timestamp: int, id: int, description: ?string} $point
	 */
	private static function keepIfNeighbour(array $moments, int $reachSec, array &$neighbours, array $point): void
	{
		foreach ($moments as $moment)
		{
			if ($point['timestamp'] < $moment - $reachSec || $point['timestamp'] > $moment + $reachSec)
			{
				continue;
			}

			$side = $point['timestamp'] <= $moment ? 'before' : 'after';
			$kept = $neighbours[$moment][$side] ?? null;

			$isCloser = $kept === null
				|| ($side === 'before'
					? self::isWrittenAfter($point, $kept)
					: self::isWrittenAfter($kept, $point));

			if ($isCloser)
			{
				$neighbours[$moment][$side] = $point;
			}
		}
	}

	/**
	 * The order of the timeline: by time, and by id for the points sharing a time - a point written later
	 * stands later, whichever second it carries.
	 *
	 * @param array{timestamp: int, id: int} $point
	 * @param array{timestamp: int, id: int} $other
	 */
	private static function isWrittenAfter(array $point, array $other): bool
	{
		return $point['timestamp'] === $other['timestamp']
			? $point['id'] > $other['id']
			: $point['timestamp'] > $other['timestamp'];
	}

	/**
	 * The neighbours of every moment as one timeline: a point neighbouring two moments is held once, and
	 * the ids leave with the sorting - they are its tie-break and say nothing to a caller.
	 *
	 * @param array<int, array<string, array{timestamp: int, id: int, description: ?string}>> $neighbours
	 * @return array<int, array{timestamp: int, description: ?string}>
	 */
	private static function asTimeline(array $neighbours): array
	{
		$points = [];
		foreach ($neighbours as $sides)
		{
			foreach ($sides as $point)
			{
				$points[$point['timestamp'] . ':' . $point['id']] = $point;
			}
		}

		$points = array_values($points);
		usort(
			$points,
			static fn (array $point, array $other): int
				=> [$point['timestamp'], $point['id']] <=> [$other['timestamp'], $other['id']],
		);

		return array_map(
			static fn (array $point): array => [
				'timestamp' => $point['timestamp'],
				'description' => $point['description'],
			],
			$points,
		);
	}

	/**
	 * The spans of the reach around the moments, overlapping ones merged: the reach is wide and the moments
	 * of one request usually sit on the same day, so merging keeps the filter short.
	 *
	 * @param int[] $moments
	 * @return array<int, array{0: int, 1: int}>
	 */
	private static function mergeSpans(array $moments, int $reachSec): array
	{
		sort($moments);

		$spans = [];
		foreach ($moments as $moment)
		{
			$last = array_key_last($spans);
			if ($last !== null && $moment - $reachSec <= $spans[$last][1])
			{
				$spans[$last][1] = max($spans[$last][1], $moment + $reachSec);

				continue;
			}

			$spans[] = [$moment - $reachSec, $moment + $reachSec];
		}

		return $spans;
	}

	/**
	 * @param array<int, array{0: int, 1: int}> $spans
	 */
	private static function rawSpansFilter(array $spans): ConditionTree
	{
		$filter = Query::filter()->logic(ConditionTree::LOGIC_OR);
		foreach ($spans as [$low, $high])
		{
			$filter->where(
				Query::filter()
					->where('DATE_CREATE', '>=', DateTime::createFromTimestamp($low))
					->where('DATE_CREATE', '<=', DateTime::createFromTimestamp($high))
			);
		}

		return $filter;
	}

	/**
	 * The days of the spans that fit the budget of one slice, weighed before they are read. The weighing is
	 * not free: LENGTH() over a LONGTEXT reads the value and not a header, so the row cap bounds what one
	 * weighing may read. The newest day comes first and the budget cuts the far end - cutting the old end
	 * can only drop the evidence that admits a point past the window, never the evidence that refuses it,
	 * so a cut slice decides no softer than a whole one. The first day goes in whatever it weighs.
	 *
	 * @param array<int, array{0: int, 1: int}> $spans
	 * @return int[]
	 */
	private static function aggregatedIdsWithinBudget(int $userId, array $spans): array
	{
		$sizes = CheckInByDayTable::query()
			->registerRuntimeField(new ExpressionField('GEO_LEN', 'LENGTH(%s)', ['GEO_DATA']))
			->setSelect(['ID', 'GEO_LEN'])
			->where('USER_ID', $userId)
			->where(self::aggregatedSpansFilter($spans))
			->setOrder(['DATE_CREATE' => 'DESC', 'ID' => 'DESC'])
			->setLimit(self::AROUND_AGGREGATED_MAX_ROWS)
			->exec()
			->fetchAll()
		;

		$ids = [];
		$bytes = 0;

		foreach ($sizes as $size)
		{
			$rowBytes = (int)($size['GEO_LEN'] ?? 0);
			if (!empty($ids) && ($bytes + $rowBytes) > self::AROUND_AGGREGATED_BYTES)
			{
				break;
			}

			$ids[] = (int)$size['ID'];
			$bytes += $rowBytes;
		}

		return $ids;
	}

	/**
	 * A day of the route is taken while its own interval meets a span, and every branch is bounded on both
	 * sides. Without the lower bound the branches collapse into one range open on the left over
	 * ix_st_check_in_by_day_user_date, so the read starts at the very first day of the employee. A day
	 * cannot begin earlier than AGGREGATED_DAY_MAX_LENGTH_SEC before it ends, so the bound drops no day.
	 *
	 * @param array<int, array{0: int, 1: int}> $spans
	 */
	private static function aggregatedSpansFilter(array $spans): ConditionTree
	{
		$filter = Query::filter()->logic(ConditionTree::LOGIC_OR);
		foreach ($spans as [$low, $high])
		{
			$earliestStart = DateTime::createFromTimestamp($low - self::AGGREGATED_DAY_MAX_LENGTH_SEC);

			$filter->where(
				Query::filter()
					->where('DATE_CREATE', '>=', $earliestStart)
					->where('DATE_CREATE', '<=', DateTime::createFromTimestamp($high))
					->where('DATE_END', '>=', DateTime::createFromTimestamp($low))
			);
		}

		return $filter;
	}

	/**
	 * @param int $userId
	 * @param DateTime $dateStart
	 * @param DateTime $dateEnd
	 * @return CheckInCollection
	 */
	public function getUserRouteByPeriod(int $userId, DateTime $dateStart, DateTime $dateEnd): CheckInCollection
	{
		return CheckInTable::query()
			->setSelect(self::ROUTE_FIELDS)
			->where('USER_ID', $userId)
			->whereBetween('DATE_CREATE', $dateStart, $dateEnd)
			->setOrder(['DATE_CREATE' => 'ASC'])
			->fetchCollection()
			;
	}

	/**
	 * @param array $userIds
	 * @param DateTime $dateStart
	 * @param DateTime $dateEnd
	 * @return array
	 */
	public function getAllUsersCheckInByPeriod(array $userIds, DateTime $dateStart, DateTime $dateEnd): array
	{
		$userIds = array_map('intval', $userIds);
		if (empty($userIds))
		{
			return [];
		}

		return CheckInTable::query()
			->setSelect(self::ALL_FIELDS)
			->whereIn('USER_ID', $userIds)
			->whereBetween('DATE_CREATE', $dateStart, $dateEnd)
			->setOrder(['DATE_CREATE' => 'ASC'])
			->exec()
			->fetchAll()
		;
	}

	/**
	 * Rows of the period a marker can come out of: a manual check-in or an event of a stay. Technical
	 * movement points are left to {@see getUserTechnicalCheckInsInIntervals()}, which raises them only
	 * for the spans the merge of a manual check-in with a stay has to look at.
	 *
	 * @param int $userId
	 * @param DateTime $dateStart
	 * @param DateTime $dateEnd
	 * @return array
	 */
	public function getUserMarkerCheckInByPeriod(int $userId, DateTime $dateStart, DateTime $dateEnd): array
	{
		return $this->getAllUsersMarkerCheckInByPeriod([$userId], $dateStart, $dateEnd);
	}

	/**
	 * The same rows for a whole list of employees, so that a screen showing a team reads the period once
	 * instead of once per member. USER_ID is among MARKER_FIELDS, and the caller splits the answer by it.
	 *
	 * @param int[] $userIds
	 * @param DateTime $dateStart
	 * @param DateTime $dateEnd
	 * @return array
	 */
	public function getAllUsersMarkerCheckInByPeriod(array $userIds, DateTime $dateStart, DateTime $dateEnd): array
	{
		$userIds = array_map('intval', $userIds);
		if (empty($userIds))
		{
			return [];
		}

		return CheckInTable::query()
			->setSelect(self::MARKER_FIELDS)
			->whereIn('USER_ID', $userIds)
			->whereBetween('DATE_CREATE', $dateStart, $dateEnd)
			->where(self::markerFilter())
			->setOrder(['DATE_CREATE' => 'ASC'])
			->exec()
			->fetchAll()
		;
	}

	/**
	 * Technical movement points of the employee inside the given spans, the bounds themselves excluded -
	 * the same open span CheckInMarkerAggregator reads points of a gap by.
	 *
	 * @param int $userId
	 * @param array<int, array{0: int, 1: int}> $intervals disjoint spans (low, high) of unix time
	 * @return array
	 */
	public function getUserTechnicalCheckInsInIntervals(int $userId, array $intervals): array
	{
		return $this->getAllUsersTechnicalCheckInsInIntervals([$userId => $intervals]);
	}

	/**
	 * The same points for several employees at once, each with the spans of his own day: the spans of the
	 * merge are answered per employee, so the condition pairs every list with the employee it belongs to
	 * rather than letting one man's gap raise another man's track.
	 *
	 * @param array<int, array<int, array{0: int, 1: int}>> $intervalsByUser employee id => disjoint spans
	 * @return array
	 */
	public function getAllUsersTechnicalCheckInsInIntervals(array $intervalsByUser): array
	{
		$byUser = Query::filter()->logic(ConditionTree::LOGIC_OR);
		$hasAny = false;

		foreach ($intervalsByUser as $userId => $intervals)
		{
			if (empty($intervals))
			{
				continue;
			}

			$spans = Query::filter()->logic(ConditionTree::LOGIC_OR);
			foreach ($intervals as [$low, $high])
			{
				$spans->where(
					Query::filter()
						->where('DATE_CREATE', '>', DateTime::createFromTimestamp($low))
						->where('DATE_CREATE', '<', DateTime::createFromTimestamp($high))
				);
			}

			$byUser->where(
				Query::filter()
					->where('USER_ID', (int)$userId)
					->where($spans)
			);
			$hasAny = true;
		}

		if (!$hasAny)
		{
			return [];
		}

		// No order: the caller sorts the day it has assembled anyway, while an OR of spans would have
		// the database sort them for nothing.
		return CheckInTable::query()
			->setSelect(self::MARKER_FIELDS)
			->where($byUser)
			->where(self::technicalFilter())
			->exec()
			->fetchAll()
		;
	}

	/** Manual points and the two events of a stay - the only automatic points that carry a marker. */
	private static function markerFilter(): ConditionTree
	{
		return Query::filter()
			->logic(ConditionTree::LOGIC_OR)
			->where('ENTITY_TYPE', self::ENTITY_TYPE['MANUAL'])
			->whereIn('DESCRIPTION', StayEvent::values())
		;
	}

	/**
	 * The exact complement of {@see markerFilter()}, spelled out instead of negated: DESCRIPTION is
	 * nullable, and a negated IN answers NULL for a NULL description, dropping a technical point the
	 * merge has to see.
	 */
	private static function technicalFilter(): ConditionTree
	{
		return Query::filter()
			->where('ENTITY_TYPE', '!=', self::ENTITY_TYPE['MANUAL'])
			->where(
				Query::filter()
					->logic(ConditionTree::LOGIC_OR)
					->whereNotIn('DESCRIPTION', StayEvent::values())
					->whereNull('DESCRIPTION')
			)
		;
	}

	/**
	 * @param array $userIds
	 * @param DateTime $dateStart
	 * @param DateTime $dateEnd
	 * @return array
	 */
	public function getAllUsersManualCheckInByPeriod(array $userIds, DateTime $dateStart, DateTime $dateEnd): array
	{
		$userIds = array_map('intval', $userIds);
		if (empty($userIds))
		{
			return [];
		}

		return CheckInTable::query()
			->setSelect(['USER_ID', 'DATE_CREATE', 'USER_TIMEZONE'])
			->whereIn('USER_ID', $userIds)
			->where('ENTITY_TYPE', self::ENTITY_TYPE['MANUAL'])
			->whereBetween('DATE_CREATE', $dateStart, $dateEnd)
			->exec()
			->fetchAll()
		;
	}

	/**
	 * Only the columns needed to resolve the local date of a manual check-in.
	 *
	 * @param int $userId
	 * @param DateTime $dateStart
	 * @param DateTime $dateEnd
	 * @return array
	 */
	public function getUserManualCheckInDatesByPeriod(int $userId, DateTime $dateStart, DateTime $dateEnd): array
	{
		return CheckInTable::query()
			->setSelect(['DATE_CREATE', 'USER_TIMEZONE'])
			->where('USER_ID', $userId)
			->where('ENTITY_TYPE', self::ENTITY_TYPE['MANUAL'])
			->whereBetween('DATE_CREATE', $dateStart, $dateEnd)
			->exec()
			->fetchAll()
		;
	}

	/**
	 * @param int $userId
	 * @param DateTime $dateStart
	 * @param DateTime $dateEnd
	 * @return array
	 */
	public function getAggregatedPointsByPeriod(int $userId, DateTime $dateStart, DateTime $dateEnd): array
	{
		return $this->fetchAggregatedPoints([$userId], $dateStart, $dateEnd);
	}

	public function getUserAggregatedManualPointsByPeriod(int $userId, DateTime $dateStart, DateTime $dateEnd): array
	{
		return $this->fetchAggregatedPoints([$userId], $dateStart, $dateEnd, self::ENTITY_TYPE['MANUAL']);
	}

	/**
	 * @param array $userIds
	 * @param DateTime $dateStart
	 * @param DateTime $dateEnd
	 * @return array
	 */
	public function getAllUsersAggregatedPointsByPeriod(array $userIds, DateTime $dateStart, DateTime $dateEnd): array
	{
		$userIds = array_map('intval', $userIds);
		if (empty($userIds))
		{
			return [];
		}

		return $this->fetchAggregatedPoints($userIds, $dateStart, $dateEnd);
	}

	private function fetchAggregatedPoints(array $userIds, DateTime $dateStart, DateTime $dateEnd, ?int $entityType = null): array
	{
		$routes = CheckInByDayTable::query()
			->setSelect([
				'ID',
				'USER_ID',
				'DATE_CREATE',
				'DATE_END',
				'GEO_DATA',
			])
			->whereIn('USER_ID', $userIds)
			->where('DATE_CREATE', '<=', $dateEnd)
			->where('DATE_END', '>=', $dateStart)
			->setOrder(['DATE_CREATE' => 'ASC'])
			->exec();

		$points = [];
		$startTs = $dateStart->getTimestamp();
		$endTs = $dateEnd->getTimestamp();

		while ($route = $routes->fetch())
		{
			$geoJson = (string)($route['GEO_DATA'] ?? '');
			if ($geoJson === '')
			{
				continue;
			}

			$geoData = json_decode($geoJson, true);
			if (!is_array($geoData))
			{
				continue;
			}

			foreach ($geoData as $point)
			{
				$ts = (int)($point['DATE_CREATE'] ?? 0);
				if ($ts < $startTs || $ts > $endTs)
				{
					continue;
				}

				if ($entityType !== null && (int)($point['ENTITY_TYPE'] ?? 0) !== $entityType)
				{
					continue;
				}

				$points[] = $point;
			}
		}

		return $points;
	}

	/**
	 * Employees whose raw rows have aged past the threshold, walked by a keyset over (DATE_CREATE, USER_ID) -
	 * the order of ix_st_check_in_date_user; a cursor over USER_ID instead reads almost the whole of
	 * ix_st_check_in_user_date every call. The window ends at the border of the days, not at the threshold
	 * ({@see staleWalkBound()}): the fold takes a day only once the threshold has passed its last second.
	 *
	 * @param DateTime $threshold
	 * @param int[] $cursor (DATE_CREATE unix, USER_ID) of the last row already walked; empty starts at the oldest one
	 * @param int $limit rows of the window read in one call - not employees returned
	 * @return array{userIds: int[], cursor: int[]}
	 */
	public function findUsersWithStaleCheckIns(DateTime $threshold, array $cursor, int $limit): array
	{
		$query = CheckInTable::query()
			->setSelect(['USER_ID', 'DATE_CREATE'])
			->where('DATE_CREATE', '<', self::staleWalkBound($threshold))
			->setOrder(['DATE_CREATE' => 'ASC', 'USER_ID' => 'ASC'])
			->setLimit($limit)
		;

		if (count($cursor) === 2)
		{
			$after = DateTime::createFromTimestamp((int)$cursor[0]);
			$sqlHelper = Application::getConnection()->getSqlHelper();
			$afterDateCreate = $sqlHelper->convertToDbDateTime($after);
			$afterUserId = (int)$cursor[1];

			// The row-value comparison is the exact predicate but not a sargable one: without the bound on the
			// leading column the range starts at the beginning of the window and every call rereads it.
			$query
				->where('DATE_CREATE', '>=', $after)
				->whereExpr("(%s, %s) > ({$afterDateCreate}, {$afterUserId})", ['DATE_CREATE', 'USER_ID'])
			;
		}

		$rows = $query->exec()->fetchAll();
		if (empty($rows))
		{
			return ['userIds' => [], 'cursor' => $cursor];
		}

		$userIds = [];
		foreach ($rows as $row)
		{
			$userIds[(int)$row['USER_ID']] = true;
		}

		return [
			'userIds' => array_keys($userIds),
			'cursor' => self::staleOwnerCursorOf($rows[array_key_last($rows)]),
		];
	}

	/**
	 * @param array $row
	 * @return int[] (DATE_CREATE, USER_ID) of the row - the key the walk over the owners goes by
	 */
	private static function staleOwnerCursorOf(array $row): array
	{
		$dateCreate = $row['DATE_CREATE'] ?? null;

		return [
			$dateCreate instanceof DateTime ? $dateCreate->getTimestamp() : 0,
			(int)$row['USER_ID'],
		];
	}

	/**
	 * The oldest calendar day of the employee lying below the threshold in full, its last second included.
	 * A day the threshold cuts in half is not offered at all: the route row is keyed by the day, so folding
	 * the beginning of one would mean the rest of it is merged into that row on the next run.
	 *
	 * @param int $userId
	 * @param DateTime $threshold
	 * @return string|null day of the server calendar, the unit CheckInAggregator groups a route row by
	 */
	public function findOldestFullyStaleDay(int $userId, DateTime $threshold): ?string
	{
		// MIN() and not ORDER BY DATE_CREATE ASC LIMIT 1: the sorted form reads the whole unfolded tail of
		// the employee since ix_st_check_in_user_type_date turned it into a skip scan with a sort on top.
		// The value still arrives as a DateTime: MIN() keeps the datetime type of the column.
		$row = CheckInTable::query()
			->addSelect(Query::expr()->min('DATE_CREATE'), 'OLDEST')
			->where('USER_ID', $userId)
			->where('DATE_CREATE', '<=', $threshold)
			->exec()
			->fetch()
		;

		$oldest = $row['OLDEST'] ?? null;
		if (!$oldest instanceof DateTime)
		{
			return null;
		}

		$day = $oldest->format(self::DAY_FORMAT);
		[, $dayEnd] = self::boundsOfDay($day);

		return $dayEnd->getTimestamp() <= $threshold->getTimestamp() ? $day : null;
	}

	/**
	 * @param int $userId
	 * @param string $day
	 * @return int
	 */
	public function countCheckInRowsOfDay(int $userId, string $day): int
	{
		[$dayStart, $dayEnd] = self::boundsOfDay($day);

		$row = CheckInTable::query()
			->addSelect(Query::expr()->count('ID'), 'CNT')
			->where('USER_ID', $userId)
			->whereBetween('DATE_CREATE', $dayStart, $dayEnd)
			->exec()
			->fetch()
		;

		return (int)($row['CNT'] ?? 0);
	}

	/**
	 * Raw rows of one calendar day of the employee, oldest first. The day is not trimmed by the aggregation
	 * threshold: a day is read only once it lies below it in full. The id breaks the tie of a shared second,
	 * so a limited read cuts the day in the same place every time - the chunked fold relies on that.
	 *
	 * @param int $userId
	 * @param string $day
	 * @param int|null $limit set only for a day too big to be held in memory at once
	 * @return array
	 */
	public function getCheckInRowsOfDay(int $userId, string $day, ?int $limit = null): array
	{
		[$dayStart, $dayEnd] = self::boundsOfDay($day);

		$query = CheckInTable::query()
			->setSelect(self::ALL_FIELDS)
			->where('USER_ID', $userId)
			->whereBetween('DATE_CREATE', $dayStart, $dayEnd)
			->setOrder(['DATE_CREATE' => 'ASC', 'ID' => 'ASC'])
		;

		if ($limit !== null)
		{
			$query->setLimit($limit);
		}

		return $query->exec()->fetchAll();
	}

	/**
	 * @return array{0: DateTime, 1: DateTime}
	 */
	private static function boundsOfDay(string $day): array
	{
		return [
			DateTime::createFromTimestamp((int)strtotime($day . ' 00:00:00')),
			DateTime::createFromTimestamp((int)strtotime($day . ' 23:59:59')),
		];
	}

	/**
	 * Where the walk over the stale window ends: the midnight opening the day of the second past the
	 * threshold, in the server calendar the fold cuts its days by. The second past the threshold and not
	 * the threshold itself, so that the bound says exactly what {@see findOldestFullyStaleDay()} takes.
	 */
	private static function staleWalkBound(DateTime $threshold): DateTime
	{
		$firstSecondAbove = DateTime::createFromTimestamp($threshold->getTimestamp() + 1);
		[$dayStart] = self::boundsOfDay($firstSecondAbove->format(self::DAY_FORMAT));

		return $dayStart;
	}

	/**
	 * @param array $userIds
	 * @param Date $localDate
	 * @return array
	 */
	public function getDailyStatsByUsers(array $userIds, Date $localDate): array
	{
		$userIds = array_map('intval', $userIds);
		if (empty($userIds))
		{
			return [];
		}

		return CheckInDailyStatsTable::query()
			->setSelect(['USER_ID', 'CNT', 'HAS_OPEN_ENTER', 'LAST_CHECK_IN_ID'])
			->whereIn('USER_ID', $userIds)
			->where('LOCAL_DATE', $localDate)
			->exec()
			->fetchAll()
		;
	}

	/**
	 * @param array $checkInIds
	 * @return array
	 */
	public function getCheckInsByIds(array $checkInIds): array
	{
		$checkInIds = array_map('intval', array_filter($checkInIds));
		if (empty($checkInIds))
		{
			return [];
		}

		return CheckInTable::query()
			->setSelect(self::ALL_FIELDS)
			->whereIn('ID', $checkInIds)
			->exec()
			->fetchAll()
		;
	}

	public function updateAddressById(int $checkInId, string $address): void
	{
		CheckInTable::update($checkInId, ['ADDRESS' => $address]);
	}

	public function updateAddressByIds(array $checkInIds, string $address): void
	{
		if (empty($checkInIds))
		{
			return;
		}

		CheckInTable::updateMulti($checkInIds, ['ADDRESS' => $address], true);
	}

	/**
	 * Raw rows of the cleanup window, walked by a keyset over (DATE_CREATE, USER_ID, ID) - the leaf order of
	 * ix_st_check_in_date_user followed by the primary key. A keyset by (DATE_CREATE, ID) would leave rows
	 * of a shared second behind for good, and sorting by it would cost a filesort over the whole window.
	 * HAS_GEO and not the geo columns: they are CryptoFields, decrypted on every row of every batch.
	 *
	 * @param DateTime $windowStart
	 * @param DateTime $threshold
	 * @param int[] $cursor (DATE_CREATE unix, USER_ID, ID) of the last row already walked; empty starts at the window
	 * @param int $limit
	 * @return array
	 */
	public function getStaleRawCheckIns(DateTime $windowStart, DateTime $threshold, array $cursor = [], int $limit = 500): array
	{
		$query = CheckInTable::query()
			->registerRuntimeField(new ExpressionField(
				'HAS_GEO',
				'CASE WHEN %s IS NOT NULL OR %s IS NOT NULL OR %s IS NOT NULL THEN 1 ELSE 0 END',
				['LATITUDE', 'LONGITUDE', 'ADDRESS'],
			))
			->setSelect(['ID', 'USER_ID', 'DATE_CREATE', 'HAS_GEO', 'MESSAGE_IDS'])
			->where('DATE_CREATE', '>', $windowStart)
			->where('DATE_CREATE', '<=', $threshold)
			->setOrder(['DATE_CREATE' => 'ASC', 'USER_ID' => 'ASC', 'ID' => 'ASC'])
			->setLimit($limit)
		;

		if (count($cursor) === 3)
		{
			$after = DateTime::createFromTimestamp((int)$cursor[0]);
			$sqlHelper = Application::getConnection()->getSqlHelper();
			$afterDateCreate = $sqlHelper->convertToDbDateTime($after);
			$afterUserId = (int)$cursor[1];
			$afterId = (int)$cursor[2];

			// A row-value comparison rather than a chain of ORs: the range optimizer turns it into one seek,
			// while an OR over three branches falls back to scanning DATE_CREATE. It is not sargable, so the
			// bound on the leading column repeats a part of it in the form the range optimizer seeks by.
			$query
				->where('DATE_CREATE', '>=', $after)
				->whereExpr(
					"(%s, %s, %s) > ({$afterDateCreate}, {$afterUserId}, {$afterId})",
					['DATE_CREATE', 'USER_ID', 'ID'],
				)
			;
		}

		return $query->exec()->fetchAll();
	}

	/**
	 * @param int[] $ids
	 */
	public function clearGeoFieldsByIds(array $ids): void
	{
		$ids = array_values(array_filter(array_map('intval', $ids)));
		if (empty($ids))
		{
			return;
		}

		CheckInTable::updateMulti($ids, [
			'LATITUDE' => null,
			'LONGITUDE' => null,
			'ADDRESS' => null,
			'MESSAGE_IDS' => null,
		], true);
	}
}
