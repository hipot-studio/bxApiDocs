<?php

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\Main\Text\Emoji;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Internal\Entity\CheckInType;
use Bitrix\StaffTrack\Internal\Repository\CheckInRepository;
use Bitrix\StaffTrack\Internals\Exception\InvalidDtoException;
use Bitrix\StaffTrack\Public\Command\CheckInDto;

/**
 * Decides whether an incoming check-in point may be persisted. The service neither writes nor throws: it
 * splits the points of one employee into accepted and rejected ones, both lists keeping the request order.
 * The one thing it changes about a point is a time ahead of the server, which it pulls back to the server
 * clock. Points arrive before Emoji::encode(), so string limits are measured on the value the client sent.
 */
class PointAcceptanceService
{
	public const CODE_TIME_OUT_OF_WINDOW = 'STAFFTRACK_CHECK_IN_TIME_OUT_OF_WINDOW';

	public const CODE_DAILY_QUOTA_EXCEEDED = 'STAFFTRACK_CHECK_IN_DAILY_QUOTA_EXCEEDED';

	public const CODE_INVALID_POINT = 'STAFFTRACK_CHECK_IN_INVALID_POINT';

	/**
	 * Lower bound of the acceptance window: the lifetime of the client outbox (86400) plus slack for clock
	 * drift and delivery (43200). Must stay below the aggregation delay of the raw rows, or the deduplication
	 * loses the row a repeat is matched against. Only {@see self::DESCRIPTION_LEAVE} may reach past it.
	 */
	public const POINT_ACCEPT_PAST_SEC = 129600;

	/**
	 * Upper bound of the acceptance window. It only has to cover the usual forward drift of a device clock:
	 * the last check-in marker is guarded by {@see self::isLastCheckInCandidate()}, not by this bound.
	 */
	public const POINT_ACCEPT_FUTURE_SEC = 3600;

	/**
	 * How far ahead of the server a client clock may run before the point is stamped with the server time
	 * instead. Below it the point keeps the time it was sent with - that time is the key its outbox holds it
	 * by and the key a repeat of it is recognised by. Above it nothing is refused for a clock alone, and no
	 * stored time reaches the day after the one the point was written in.
	 */
	public const FUTURE_CLOCK_TOLERANCE_SEC = 300;

	/**
	 * Tolerance of the coordinate comparison in the natural key. It must stay no coarser than a tenth of the
	 * grid step the client sends, or neighbouring nodes collapse into one: five decimals give 1.1e-5, hence
	 * 1e-6. A tolerance is needed at all because the value travels float -> string column -> float.
	 */
	public const COORD_MATCH_EPSILON = 1e-6;

	/**
	 * Daily ceiling of automatic points of one employee. Must stay strictly above the client daily budget (3000
	 * points, stafftrackmobile check-in-v3/auto-check-in): the budget only coarsens the stream, this ceiling
	 * cuts it off and the client deletes the refused points for good. The real cap on emission is not that
	 * budget but MIN_INTERVAL_SEC of the client, which the budget now raises: the interval follows the
	 * coarsening, so the floor of 5 s becomes 20 s once the multiplier is at its ceiling. A receiver
	 * understating its error still breaks this quota: one twelve-hour shift of standing under such a receiver
	 * measures 9226 points on the corpus of the track simulator before the coarsening reached the interval and
	 * the deviation corridor, and 4453 after, so the day now passes the quota by a tenth of it instead of by
	 * more than twice. A deliberate trade, not a closed hole.
	 */
	public const AUTO_POINT_DAILY_QUOTA = 4000;

	/**
	 * Name of the lock a write path takes around its acceptance decision and the write that follows it, one
	 * per employee. The deduplication and the quota are read here and spent by the write, so two paths of one
	 * employee under different names would read the same remainder and both write.
	 */
	public const ACCEPT_LOCK_PREFIX = 'stafftrack_check_in_accept_';

	/**
	 * The point that closes the route of its day. The client keeps it in its outbox at any age, so the lower
	 * bound of the window may not drop it by age alone: past the bound it is admitted only while it closes the
	 * tail of an open segment, which out there also replaces the deduplication - the raw rows are gone.
	 */
	private const DESCRIPTION_LEAVE = 'LEAVE';

	/**
	 * How many closing points past the window one batch is examined for, the oldest ones first. A client
	 * carries one closing point per open segment, so a long offline device has units of them behind it. The
	 * candidates past the ceiling keep the rejection by age they already carry and cost no read of their own.
	 */
	public const MAX_ROUTE_CLOSING_CANDIDATES_PER_BATCH = 8;

	/**
	 * How far back the closing point may look for the segment it closes, and how far ahead it checks that the
	 * segment is still open. A stay writes no point between its two events, so the silence before it lasts a
	 * whole shift: a day of that plus half for drift. Too short costs a route its end, too long one stray point.
	 */
	public const LEAVE_CLOSE_LOOKBACK_SEC = 129600;

	private CheckInRepository $repository;

	public function __construct(?CheckInRepository $repository = null)
	{
		$this->repository = $repository ?? new CheckInRepository();
	}

	/**
	 * Acceptance of a single point: the pipeline of {@see self::acceptBatch()} without the deduplication
	 * step. The single write path carries no retry of its own, so it has nothing to collapse.
	 *
	 * @param int $userId employee the points belong to
	 * @param int|null $userTimezone offset of the employee profile relative to the server
	 * @param CheckInDto[] $points points as the client sent them, before Emoji::encode()
	 * @param int|null $now moment of acceptance, server time by default
	 * @return array{
	 *     accepted: array<int, array{point: CheckInDto, duplicateOf: CheckInDto|int|null}>,
	 *     rejected: array<int, array{point: CheckInDto, code: string}>
	 * }
	 */
	public function accept(int $userId, ?int $userTimezone, array $points, ?int $now = null): array
	{
		return $this->decide($userId, $userTimezone, $points, $now ?? time(), withDeduplication: false);
	}

	/**
	 * Splits the points of one employee into the ones a write path may persist and the ones it must reject.
	 * The time of a rejected point is never trimmed: it belongs to the outbox key of the client and to the
	 * projection key of the map. A duplicate is accepted, carries its twin, is left out of the write and
	 * shares that twin outcome. The caller must hold the acceptance lock of the employee around the write.
	 *
	 * @param int $userId employee the whole batch belongs to
	 * @param int|null $userTimezone offset of the employee profile relative to the server
	 * @param CheckInDto[] $points points as the client sent them, before Emoji::encode()
	 * @param int|null $now moment of acceptance, server time by default
	 * @return array{
	 *     accepted: array<int, array{point: CheckInDto, duplicateOf: CheckInDto|int|null}>,
	 *     rejected: array<int, array{point: CheckInDto, code: string}>
	 * }
	 */
	public function acceptBatch(int $userId, ?int $userTimezone, array $points, ?int $now = null): array
	{
		return $this->decide($userId, $userTimezone, $points, $now ?? time(), withDeduplication: true);
	}

	/**
	 * The decisions are a pipeline with a fixed order - validation, time, deduplication, quota - and the
	 * first one that fires is final: a rejected point neither joins the deduplication nor spends the quota.
	 * Between time and deduplication a route-closing point may be let back past the lower bound. A time
	 * ahead of the server is pulled back before any of it, so every step reads the time that will be stored.
	 *
	 * @param CheckInDto[] $points
	 * @return array{
	 *     accepted: array<int, array{point: CheckInDto, duplicateOf: CheckInDto|int|null}>,
	 *     rejected: array<int, array{point: CheckInDto, code: string}>
	 * }
	 */
	private function decide(
		int $userId,
		?int $userTimezone,
		array $points,
		int $now,
		bool $withDeduplication,
	): array
	{
		$states = [];
		foreach ($points as $point)
		{
			// Only where the client does not hold the point by its time. On the batch path the time is the
			// key of the outbox, of the repeat and of the projection of the map: rewriting it there makes a
			// repeated batch unrecognisable, collapses the points of one stay into a single row and flattens
			// the time of a batch into one instant.
			if (!$withDeduplication)
			{
				$this->pullFutureTimeToTheServerClock($point, $now);
			}

			$states[] = [
				'point' => $point,
				'code' => $this->codeOfPoint($point, $now),
				'duplicateOf' => null,
			];
		}

		$this->admitRouteClosingPoints($userId, $states, $now);

		if ($withDeduplication)
		{
			$this->findDuplicates($userId, $states);
		}

		$this->checkQuota($userId, (int)($userTimezone ?? 0), $states, $now);

		if ($withDeduplication)
		{
			$this->shareTheFateOfTheTwin($states);
		}

		$accepted = [];
		$rejected = [];
		foreach ($states as $state)
		{
			if ($state['code'] === null)
			{
				$accepted[] = [
					'point' => $state['point'],
					'duplicateOf' => $state['duplicateOf'],
				];
			}
			else
			{
				$rejected[] = [
					'point' => $state['point'],
					'code' => $state['code'],
				];
			}
		}

		return [
			'accepted' => $accepted,
			'rejected' => $rejected,
		];
	}

	/**
	 * Marks the points that repeat a point of the same employee, stored or earlier in this very batch. The
	 * candidates are raised by exact timestamps, not by their range: a catch-up batch spans hours and a range
	 * would decrypt the whole day. A CryptoField is not deterministic, so coordinates are compared in PHP.
	 *
	 * @param array<int, array{point: CheckInDto, code: ?string, duplicateOf: CheckInDto|int|null}> $states
	 */
	private function findDuplicates(int $userId, array &$states): void
	{
		$dates = [];
		foreach ($states as $state)
		{
			$timestamp = $this->keyTimestamp($state);
			if ($timestamp !== null)
			{
				$dates[$timestamp] ??= DateTime::createFromTimestamp($timestamp);
			}
		}

		if (empty($dates))
		{
			return;
		}

		$index = [];
		foreach ($this->repository->findPointsForDedup($userId, array_values($dates)) as $row)
		{
			$key = $this->keyOfStored($userId, $row);
			$index[$key][] = [
				'latitude' => (float)$row['LATITUDE'],
				'longitude' => (float)$row['LONGITUDE'],
				'twin' => (int)$row['ID'],
			];
		}

		foreach ($states as $position => $state)
		{
			$timestamp = $this->keyTimestamp($state);
			if ($timestamp === null)
			{
				continue;
			}

			$point = $state['point'];
			$key = $this->keyOfInput($userId, $point, $timestamp);

			foreach ($index[$key] ?? [] as $candidate)
			{
				if (
					abs($candidate['latitude'] - $point->latitude) <= self::COORD_MATCH_EPSILON
					&& abs($candidate['longitude'] - $point->longitude) <= self::COORD_MATCH_EPSILON
				)
				{
					// The twin itself and not its id: a twin taken from this very batch has no id until
					// the write, and the caller fills it in once the rows are there.
					$states[$position]['duplicateOf'] = $candidate['twin'];

					continue 2;
				}
			}

			$index[$key][] = [
				'latitude' => $point->latitude,
				'longitude' => $point->longitude,
				'twin' => $point,
			];
		}
	}

	/**
	 * A point joins the deduplication only while it carries a time of its own and no decision has been taken
	 * on it: without DATE_CREATE it has no natural key, the write path stamping it with the server time.
	 *
	 * @param array{point: CheckInDto, code: ?string, duplicateOf: CheckInDto|int|null} $state
	 */
	private function keyTimestamp(array $state): ?int
	{
		if ($state['code'] !== null)
		{
			return null;
		}

		return $state['point']->dateCreate?->getTimestamp();
	}

	/**
	 * The key is compared in the form the description has in the column, that is after Emoji::encode(). The
	 * service sees a point before the encoding, so the conversion happens here and nowhere else.
	 */
	private function keyOfInput(int $userId, CheckInDto $point, int $timestamp): string
	{
		$description = $point->description === null ? null : Emoji::encode($point->description);

		return $this->key($userId, $timestamp, $point->entityType, $description);
	}

	private function keyOfStored(int $userId, array $row): string
	{
		return $this->key(
			$userId,
			$row['DATE_CREATE'] instanceof DateTime ? $row['DATE_CREATE']->getTimestamp() : 0,
			(int)$row['ENTITY_TYPE'],
			$row['DESCRIPTION'] === null ? null : (string)$row['DESCRIPTION'],
		);
	}

	private function key(int $userId, int $timestamp, int $entityType, ?string $description): string
	{
		return (string)json_encode([$userId, $timestamp, $entityType, $description]);
	}

	/**
	 * Rejects the automatic points that do not fit into the daily quota of their local day. One COUNT per
	 * day, deferred until the first point that actually spends the quota, so a manual-only day pays none.
	 *
	 * @param array<int, array{point: CheckInDto, code: ?string, duplicateOf: CheckInDto|int|null}> $states
	 */
	private function checkQuota(int $userId, int $userTimezone, array &$states, int $now): void
	{
		$days = [];
		foreach ($states as $position => $state)
		{
			$timestamp = $this->effectiveTimestamp($state['point'], $now);
			$days[date('Y-m-d', $timestamp + $userTimezone)][] = $position;
		}

		foreach ($days as $localDate => $positions)
		{
			$this->applyDayQuota($userId, $userTimezone, (string)$localDate, $positions, $states, $now);
		}
	}

	/**
	 * @param int[] $positions
	 * @param array<int, array{point: CheckInDto, code: ?string, duplicateOf: CheckInDto|int|null}> $states
	 */
	private function applyDayQuota(
		int $userId,
		int $userTimezone,
		string $localDate,
		array $positions,
		array &$states,
		int $now,
	): void
	{
		// The order the quota is spent in is the chronological one, and points sharing a timestamp keep
		// the order of the request, so the outcome of a batch is reproducible from run to run.
		$queue = [];
		foreach ($positions as $position)
		{
			$queue[$position] = $this->effectiveTimestamp($states[$position]['point'], $now);
		}
		asort($queue);

		$budget = null;
		foreach (array_keys($queue) as $position)
		{
			$state = $states[$position];
			if (
				$state['code'] !== null
				|| $state['duplicateOf'] !== null
				|| $state['point']->entityType !== CheckInType::AUTOMATIC->value
			)
			{
				continue;
			}

			if ($budget === null)
			{
				[$dateStart, $dateEnd] = self::boundsOfLocalDay($localDate, $userTimezone);
				$budget = self::AUTO_POINT_DAILY_QUOTA
					- $this->repository->countAutomaticByPeriod($userId, $dateStart, $dateEnd);
			}

			if ($budget <= 0)
			{
				$states[$position]['code'] = self::CODE_DAILY_QUOTA_EXCEEDED;
			}
			else
			{
				$budget--;
			}
		}
	}

	/**
	 * A duplicate of a point of this very batch shares the fate of the twin it repeats: the twin spends the
	 * quota for both, so a duplicate of a refused twin reported as accepted would be answered with the id of
	 * a row never written. A duplicate of a stored row keeps its own outcome - that row is there regardless.
	 *
	 * @param array<int, array{point: CheckInDto, code: ?string, duplicateOf: CheckInDto|int|null}> $states
	 */
	private function shareTheFateOfTheTwin(array &$states): void
	{
		$codeOfPoint = [];
		foreach ($states as $state)
		{
			$codeOfPoint[spl_object_id($state['point'])] = $state['code'];
		}

		foreach ($states as $position => $state)
		{
			$twin = $state['duplicateOf'];
			if ($state['code'] !== null || !($twin instanceof CheckInDto))
			{
				continue;
			}

			$states[$position]['code'] = $codeOfPoint[spl_object_id($twin)] ?? null;
		}
	}

	/**
	 * A point with no time of its own is counted into the local day of the moment it is accepted - exactly
	 * the time the write path is about to stamp it with. One rule for the day group and the quota queue.
	 */
	private function effectiveTimestamp(CheckInDto $point, int $now): int
	{
		return $point->dateCreate?->getTimestamp() ?? $now;
	}

	/**
	 * Bounds of a local day of the employee: USER_TIMEZONE is the offset of the profile relative to the
	 * server, not to UTC, so the bounds use server date()/strtotime(). The upper bound comes from the next
	 * midnight rather than from + 86399: a daylight saving day lasts 82800 or 90000 seconds.
	 *
	 * @return array{0: DateTime, 1: DateTime}
	 */
	public static function boundsOfLocalDay(string $localDate, int $userTimezone): array
	{
		$from = (int)strtotime($localDate . ' 00:00:00') - $userTimezone;
		$to = (int)strtotime($localDate . ' 00:00:00 +1 day') - $userTimezone - 1;

		return [
			DateTime::createFromTimestamp($from),
			DateTime::createFromTimestamp($to),
		];
	}

	/**
	 * The two decisions taken over the point alone, before anything reads the database. Validation comes
	 * first because the rest of the pipeline works on rubbish without it: a coordinate out of range would
	 * take part in the deduplication, and a point that creates no row would still spend the quota.
	 */
	private function codeOfPoint(CheckInDto $point, int $now): ?string
	{
		if (!$this->isValid($point))
		{
			return self::CODE_INVALID_POINT;
		}

		if (!$this->isTimeAcceptable($point, $now))
		{
			return self::CODE_TIME_OUT_OF_WINDOW;
		}

		return null;
	}

	/**
	 * The composition of the checks is declared by the attributes of {@see CheckInDto}. A point that fails
	 * them is rejected on its own: a single broken point of a batch must not take the other ninety-nine
	 * down, because the client would then repeat the whole batch until that point ages out.
	 */
	private function isValid(CheckInDto $point): bool
	{
		try
		{
			$point->validateAdd();
		}
		catch (InvalidDtoException)
		{
			return false;
		}

		return true;
	}

	/**
	 * A clock running fast costs the point its own time and not the write: past the tolerance the time is
	 * replaced with the server one, as the write paths did before this service. Only the future side is
	 * touched - moving a point back would be a day of presence written behind the window, which is what the
	 * window is there to refuse.
	 */
	private function pullFutureTimeToTheServerClock(CheckInDto $point, int $now): void
	{
		$timestamp = $point->dateCreate?->getTimestamp();
		if ($timestamp === null || $timestamp <= $now + self::FUTURE_CLOCK_TOLERANCE_SEC)
		{
			return;
		}

		$point->setDateCreate(DateTime::createFromTimestamp($now));
	}

	/**
	 * The window bounds the time of every point alike: the creation time comes from the client on both write
	 * paths, and a type exempt from it would be a whole day of presence written backwards. Ahead of the
	 * server the bound is reached only by a time the tolerance left alone. A point with no time is accepted;
	 * a route-closing one gets a second look in {@see self::admitRouteClosingPoints()}.
	 */
	private function isTimeAcceptable(CheckInDto $point, int $now): bool
	{
		if ($point->dateCreate === null)
		{
			return true;
		}

		$timestamp = $point->dateCreate->getTimestamp();

		return $timestamp <= $now + self::POINT_ACCEPT_FUTURE_SEC
			&& $timestamp >= $now - self::POINT_ACCEPT_PAST_SEC;
	}

	/**
	 * Takes the rejection by age back off the points that close a route and have a route to close. Every
	 * other rejection stands, a manual check-in described as LEAVE included - that description is a string a
	 * person types. Candidates are examined oldest first, one admitted closing the segment for the rest, and
	 * only the oldest {@see self::MAX_ROUTE_CLOSING_CANDIDATES_PER_BATCH} take part in the single read.
	 *
	 * @param array<int, array{point: CheckInDto, code: ?string, duplicateOf: CheckInDto|int|null}> $states
	 */
	private function admitRouteClosingPoints(int $userId, array &$states, int $now): void
	{
		$candidates = [];
		foreach ($states as $position => $state)
		{
			$timestamp = $state['point']->dateCreate?->getTimestamp();
			if (
				$state['code'] !== self::CODE_TIME_OUT_OF_WINDOW
				|| $timestamp === null
				|| $timestamp >= $now - self::POINT_ACCEPT_PAST_SEC
				|| !self::isRouteClosingPoint($state['point'])
			)
			{
				continue;
			}

			$candidates[$position] = $timestamp;
		}

		if (empty($candidates))
		{
			return;
		}

		asort($candidates);
		$candidates = array_slice(
			$candidates,
			0,
			self::MAX_ROUTE_CLOSING_CANDIDATES_PER_BATCH,
			preserve_keys: true,
		);

		$timeline = $this->repository->getAutomaticPointsAround(
			$userId,
			array_values($candidates),
			self::LEAVE_CLOSE_LOOKBACK_SEC,
		);

		$admitted = [];
		foreach ($candidates as $position => $timestamp)
		{
			if (!self::closesTheTailOfAnOpenSegment($timestamp, $timeline, $admitted))
			{
				continue;
			}

			$states[$position]['code'] = null;
			$admitted[] = $timestamp;
		}
	}

	private static function isRouteClosingPoint(CheckInDto $point): bool
	{
		return $point->entityType === CheckInType::AUTOMATIC->value
			&& $point->description === self::DESCRIPTION_LEAVE;
	}

	/**
	 * Whether the closing point stands at the tail of an open segment: the latest automatic point within
	 * reach at or before it is not a closing point itself, and nothing of the employee stands after it within
	 * reach. The look ahead keeps this a rule of the outbox rather than a way of backdating a written day.
	 * Closing points admitted earlier in this batch count as written, the ordinary points of it do not.
	 *
	 * @param array<int, array{timestamp: int, description: ?string}> $timeline automatic points of the
	 *        employee around the candidates of this batch, ascending
	 * @param int[] $admitted times of the closing points already admitted in this batch
	 */
	private static function closesTheTailOfAnOpenSegment(int $timestamp, array $timeline, array $admitted): bool
	{
		$from = $timestamp - self::LEAVE_CLOSE_LOOKBACK_SEC;
		$to = $timestamp + self::LEAVE_CLOSE_LOOKBACK_SEC;

		$latest = null;
		foreach ($timeline as $point)
		{
			$pointTimestamp = (int)$point['timestamp'];

			if ($pointTimestamp > $timestamp)
			{
				if ($pointTimestamp <= $to)
				{
					return false;
				}

				continue;
			}

			if ($pointTimestamp >= $from && ($latest === null || $pointTimestamp >= (int)$latest['timestamp']))
			{
				$latest = $point;
			}
		}

		foreach ($admitted as $admittedTimestamp)
		{
			if (
				$admittedTimestamp >= $from
				&& $admittedTimestamp <= $timestamp
				&& ($latest === null || $admittedTimestamp >= (int)$latest['timestamp'])
			)
			{
				return false;
			}
		}

		return $latest !== null && $latest['description'] !== self::DESCRIPTION_LEAVE;
	}

	/**
	 * Whether a point may become the LAST_CHECK_IN_ID of its local day. The marker only moves forward in
	 * time, so a point from the future would hold it until its own time arrives and the real points of the
	 * day would no longer move it. The type takes no part in the rule and is kept because callers pass both.
	 */
	public static function isLastCheckInCandidate(int $entityType, int $timestamp, ?int $now = null): bool
	{
		return $timestamp <= ($now ?? time());
	}
}
