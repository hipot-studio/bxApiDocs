<?php

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Feature;
use Bitrix\StaffTrack\Internal\Entity\CheckInType;
use Bitrix\StaffTrack\Internal\Integration\Disk\FileResolver;
use Bitrix\StaffTrack\Internal\Model\CheckInDailyStatsTable;
use Bitrix\StaffTrack\Internal\Model\CheckInTable;
use Bitrix\StaffTrack\Internal\Repository\CheckInRepository;
use Bitrix\StaffTrack\Internal\Service\CheckIn\AggregatorEventMapper;
use Bitrix\StaffTrack\Internal\Service\CheckIn\CheckInDayRowsReader;
use Bitrix\StaffTrack\Internal\Service\CheckIn\CheckInVisitCounter;
use Bitrix\StaffTrack\Public\Provider\CheckInMarkerNormalizer;

class DailyStatsUpdater
{
	private const DESCRIPTION_ENTER = 'ENTER';
	private const DESCRIPTION_LEAVE = 'LEAVE';

	private CheckInMarkerAggregator $aggregator;
	private CheckInDayRowsReader $dayRowsReader;
	private CheckInVisitCounter $visitCounter;

	/**
	 * One aggregator for the whole path: the reader asks it which spans the merge needs, the delta asks it
	 * for the markers and the counter counts those markers. Two of them would be two answers about one day.
	 */
	public function __construct(?CheckInMarkerAggregator $aggregator = null)
	{
		$this->aggregator = $aggregator ?? new CheckInMarkerAggregator();
		$this->dayRowsReader = new CheckInDayRowsReader(new CheckInRepository(), $this->aggregator);
		$this->visitCounter = new CheckInVisitCounter($this->dayRowsReader, $this->aggregator);
	}

	/**
	 * Writes the daily stats of a single check-in and builds the delta for its add push.
	 *
	 * The two are separate phases ({@see applyAdd}, {@see buildAddDelta}): a caller that owns a
	 * write transaction runs them around its commit, so that only the write is held by the
	 * transaction. This method keeps both for callers without one.
	 *
	 * The coordinates are optional: a caller of the six-argument contract has none to pass, and
	 * {@see buildAddDelta} then reads the point of the delta off the stored row.
	 *
	 * @return array{userId: int, cnt?: int, lastMarker: ?array, isTechnical: bool, point: ?array{lat: float, lon: float, time: int}}
	 */
	public function update(
		int $userId,
		int $checkInId,
		int $entityType,
		string $description,
		int $userTimezone,
		int $timestamp,
		?float $latitude = null,
		?float $longitude = null
	): array
	{
		$addState = $this->applyAdd(
			$userId,
			$checkInId,
			$entityType,
			$description,
			$userTimezone,
			$timestamp,
			$latitude,
			$longitude,
		);

		if (!Feature::isCheckInRolloutEnabled($userId))
		{
			return [];
		}

		return $this->buildAddDelta($addState);
	}

	/**
	 * Applies the daily-stats write of a single check-in.
	 *
	 * This is the whole write of the add path and the only part that belongs inside the caller's
	 * transaction; the returned state is the handoff to buildAddDelta().
	 *
	 * The coordinates travel through that state and spare the delta any reread of the day; they are
	 * optional for the same reason as on {@see update()}, and the read phase then reads the stored row.
	 *
	 * @return array{userId: int, checkInId: int, userTimezone: int, timestamp: int, latitude: ?float, longitude: ?float, localDate: Date, producesMarker: bool}
	 */
	public function applyAdd(
		int $userId,
		int $checkInId,
		int $entityType,
		string $description,
		int $userTimezone,
		int $timestamp,
		?float $latitude = null,
		?float $longitude = null
	): array
	{
		$localDate = $this->getLocalDate($timestamp, $userTimezone);
		$stats = $this->findStats($userId, $localDate) ?? $this->createStats($userId, $localDate);

		$hasOpenEnter = (bool)($stats['HAS_OPEN_ENTER'] ?? false);
		[$cntDelta, $newHasOpenEnter] = $this->calculateDelta($entityType, $description, $hasOpenEnter);

		$updateFields = [
			'CNT' => (int)($stats['CNT'] ?? 0) + $cntDelta,
			'HAS_OPEN_ENTER' => $newHasOpenEnter ? 1 : 0,
		];

		$isClosingLeave =
			$entityType !== CheckInType::MANUAL->value
			&& $description === self::DESCRIPTION_LEAVE
			&& $hasOpenEnter;

		if (
			!$isClosingLeave
			&& PointAcceptanceService::isLastCheckInCandidate($entityType, $timestamp)
			&& $this->isNewerThanLast((int)($stats['LAST_CHECK_IN_ID'] ?? 0), $timestamp + $userTimezone)
		)
		{
			$updateFields['LAST_CHECK_IN_ID'] = $checkInId;
		}

		CheckInDailyStatsTable::update($stats['ID'], $updateFields);

		return [
			'userId' => $userId,
			'checkInId' => $checkInId,
			'userTimezone' => $userTimezone,
			'timestamp' => $timestamp,
			'latitude' => $latitude,
			'longitude' => $longitude,
			'localDate' => $localDate,
			// Read off the stored HAS_OPEN_ENTER, before this point moves it - the same rule the batch
			// path applies inside its own walk.
			'producesMarker' => $this->producesMarker($entityType, $description, $hasOpenEnter),
		];
	}

	/**
	 * Builds the add-push delta for a check-in whose stats write is already committed.
	 *
	 * The technical flag and the point come out of the write phase, so the dominant case - a technical
	 * movement point - costs no query. Only a point that really produced a marker pays the day reread,
	 * which must stay outside the write transaction, and that reread is where the counter comes from.
	 *
	 * @param array{userId: int, checkInId: int, userTimezone: int, timestamp: int, latitude: ?float, longitude: ?float, localDate: Date, producesMarker: bool} $addState
	 * @return array{userId: int, cnt?: int, lastMarker: ?array, isTechnical: bool, point: ?array{lat: float, lon: float, time: int}}
	 */
	public function buildAddDelta(array $addState): array
	{
		$isTechnical = !$addState['producesMarker'];
		$day = $isTechnical ? null : $this->readDay($addState, (int)$addState['checkInId']);

		return [
			'userId' => $addState['userId'],
			...self::dayCountKey($day),
			'lastMarker' => $day['lastMarker'] ?? null,
			'isTechnical' => $isTechnical,
			'point' => $this->buildDeltaPoint($this->withStoredPoint($addState)),
		];
	}

	/**
	 * @param array<int, array> $rows
	 * @param array<int, array> $markers
	 */
	private function buildLastMarker(array $rows, array $markers, int $checkInId, int $userId, int $userTimezone): ?array
	{
		if (empty($markers))
		{
			return null;
		}

		$rawRowsById = [];
		foreach ($rows as $row)
		{
			$rawRowsById[(int)($row['ID'] ?? 0)] = $row;
		}

		$marker = $this->findMarkerOfCheckIn($markers, $checkInId) ?? $this->findLastFinishedMarker($markers);

		return CheckInMarkerNormalizer::normalize(
			$marker,
			$rawRowsById,
			$this->resolveMarkerFiles($marker, $rawRowsById, $userId),
			$userTimezone,
		);
	}

	/**
	 * The push is about the check-in that has just been written, so its own marker wins.
	 *
	 * @param array<int, array> $markers
	 */
	private function findMarkerOfCheckIn(array $markers, int $checkInId): ?array
	{
		foreach ($markers as $marker)
		{
			$isMarkerOfCheckIn =
				(int)($marker['event']['id'] ?? 0) === $checkInId
				|| (int)($marker['auto']['id'] ?? 0) === $checkInId;

			if ($isMarkerOfCheckIn)
			{
				return $marker;
			}
		}

		return null;
	}

	/**
	 * A closing LEAVE has no marker of its own, it only ends a stay. Markers are ordered by their
	 * start, and a stay is no longer broken by a manual check-in inside it, so the just closed stay
	 * can start earlier than that manual and still be the last thing to end.
	 *
	 * @param array<int, array> $markers
	 */
	private function findLastFinishedMarker(array $markers): array
	{
		$last = $markers[0];
		foreach ($markers as $marker)
		{
			if ($this->markerEnd($marker) >= $this->markerEnd($last))
			{
				$last = $marker;
			}
		}

		return $last;
	}

	private function markerEnd(array $marker): int
	{
		return (int)($marker['endTimestamp'] ?? $marker['timestamp']);
	}

	/**
	 * @param array{timestamp: int, latitude: ?float, longitude: ?float} $addState
	 * @return ?array{lat: float, lon: float, time: int}
	 */
	private function buildDeltaPoint(array $addState): ?array
	{
		if ($addState['latitude'] === null || $addState['longitude'] === null)
		{
			return null;
		}

		return [
			'lat' => (float)$addState['latitude'],
			'lon' => (float)$addState['longitude'],
			'time' => (int)$addState['timestamp'],
		];
	}

	/**
	 * The state of a caller of the six-argument contract carries no coordinates, so the point is lifted
	 * off the stored row here, in the read phase: no write transaction holds the read, and a portal
	 * without the rollout never reaches this phase at all.
	 *
	 * @param array{userId: int, checkInId: int, latitude: ?float, longitude: ?float} $addState
	 * @return array{userId: int, checkInId: int, latitude: ?float, longitude: ?float}
	 */
	private function withStoredPoint(array $addState): array
	{
		if ($addState['latitude'] !== null && $addState['longitude'] !== null)
		{
			return $addState;
		}

		[$addState['latitude'], $addState['longitude']] = $this->readPointOfCheckIn(
			(int)$addState['userId'],
			(int)$addState['checkInId'],
		);

		return $addState;
	}

	/**
	 * Coordinates of a check-in already written, for the caller that has none of its own. The row is
	 * asked for by its own key and by its owner: an id of another user is no point of this delta.
	 *
	 * @return array{0: ?float, 1: ?float}
	 */
	private function readPointOfCheckIn(int $userId, int $checkInId): array
	{
		$row = CheckInTable::query()
			->setSelect(['LATITUDE', 'LONGITUDE'])
			->where('ID', $checkInId)
			->where('USER_ID', $userId)
			->setLimit(1)
			->exec()
			->fetch();

		if (!$row)
		{
			return [null, null];
		}

		return [
			$row['LATITUDE'] !== null ? (float)$row['LATITUDE'] : null,
			$row['LONGITUDE'] !== null ? (float)$row['LONGITUDE'] : null,
		];
	}

	/**
	 * @param array{kind: string, event: array, duration: ?int, auto: ?array, timestamp: int} $marker
	 * @param array<int, array> $rawRowsById
	 * @return array
	 */
	private function resolveMarkerFiles(array $marker, array $rawRowsById, int $userId): array
	{
		if ($marker['kind'] === CheckInMarkerAggregator::KIND_STAY)
		{
			return [];
		}

		$leadingId = (int)($marker['event']['id'] ?? 0);
		$row = $rawRowsById[$leadingId] ?? null;
		if ($row === null)
		{
			return [];
		}

		$fileIds = is_array($row['FILE_IDS'] ?? null) ? $row['FILE_IDS'] : [];
		if (empty($fileIds))
		{
			return [];
		}

		return (new FileResolver())->resolve($leadingId, $fileIds, $userId);
	}

	/**
	 * Batch daily-stats recompute for a set of a single user's check-in points.
	 *
	 * Existing daily stats are read once per local-date group and written with a single
	 * update/add per group (no per-point SELECT). The HAS_OPEN_ENTER invariant requires
	 * chronological processing, so points inside each group are sorted by local time first.
	 *
	 * This is the write phase of the batch path; the returned states are the handoff to
	 * {@see buildBatchDelta()}, the same two-phase split the single add path uses.
	 *
	 * One state per local day, in date order, because a batch is allowed to cross midnight: the queue of
	 * the device lives by a sliding day and is cut into batches by size alone, so an offline night is
	 * drained by batches that carry both dates. Each of those days owes its readers a delta of its own -
	 * a screen showing the earlier one recognises nothing else.
	 *
	 * @param int $userId
	 * @param array<int, array{id: int, entityType: int, description: string, timestamp: int, latitude?: float, longitude?: float}> $points
	 * @param int $userTimezone
	 * @return array<int, array{userId: int, userTimezone: int, localDate: ?Date, markerCheckInId: int, points: array<int, array{lat: float, lon: float, time: int}>}>
	 */
	public function updateBatch(int $userId, array $points, int $userTimezone): array
	{
		if (empty($points))
		{
			return [];
		}

		$groups = [];
		foreach ($points as $point)
		{
			$localDate = $this->getLocalDate((int)($point['timestamp'] ?? 0), $userTimezone);
			$key = $localDate->format('Y-m-d');
			if (!isset($groups[$key]))
			{
				$groups[$key] = [
					'localDate' => $localDate,
					'points' => [],
				];
			}

			$groups[$key]['points'][] = $point;
		}

		// In date order: the states leave in the order their days did, and a reader taking the last of
		// them is taking the latest day rather than whichever one the batch happened to start with.
		ksort($groups);

		$batchStates = [];

		foreach ($groups as $group)
		{
			$groupPoints = $group['points'];
			usort(
				$groupPoints,
				static function (array $a, array $b) use ($userTimezone): int {
					$tsA = (int)($a['timestamp'] ?? 0) + $userTimezone;
					$tsB = (int)($b['timestamp'] ?? 0) + $userTimezone;

					return $tsA <=> $tsB ?: (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
				},
			);

			$stats = $this->findStats($userId, $group['localDate']) ?? $this->createStats($userId, $group['localDate']);

			$cnt = (int)($stats['CNT'] ?? 0);
			$hasOpenEnter = (bool)($stats['HAS_OPEN_ENTER'] ?? false);
			$lastId = (int)($stats['LAST_CHECK_IN_ID'] ?? 0);
			$lastLocalTimestamp = $lastId > 0 ? ($this->getCheckInLocalTimestamp($lastId) ?? 0) : 0;
			$markerCheckInId = 0;

			foreach ($groupPoints as $point)
			{
				$entityType = (int)($point['entityType'] ?? 0);
				$description = (string)($point['description'] ?? '');

				if ($this->producesMarker($entityType, $description, $hasOpenEnter))
				{
					$markerCheckInId = (int)($point['id'] ?? 0);
				}

				[$cntDelta, $newHasOpenEnter] = $this->calculateDelta($entityType, $description, $hasOpenEnter);
				$cnt += $cntDelta;

				$isClosingLeave =
					$entityType !== CheckInType::MANUAL->value
					&& $description === self::DESCRIPTION_LEAVE
					&& $hasOpenEnter;

				$pointTimestamp = (int)($point['timestamp'] ?? 0);
				$pointLocalTimestamp = $pointTimestamp + $userTimezone;
				if (
					!$isClosingLeave
					&& PointAcceptanceService::isLastCheckInCandidate($entityType, $pointTimestamp)
					&& $pointLocalTimestamp > $lastLocalTimestamp
				)
				{
					$lastId = (int)($point['id'] ?? 0);
					$lastLocalTimestamp = $pointLocalTimestamp;
				}

				$hasOpenEnter = $newHasOpenEnter;
			}

			CheckInDailyStatsTable::update($stats['ID'], [
				'CNT' => $cnt,
				'HAS_OPEN_ENTER' => $hasOpenEnter ? 1 : 0,
				'LAST_CHECK_IN_ID' => $lastId,
			]);

			$batchStates[] = [
				'userId' => $userId,
				'userTimezone' => $userTimezone,
				'localDate' => $group['localDate'],
				'markerCheckInId' => $markerCheckInId,
				'points' => $this->extractDeltaPoints($groupPoints),
			];
		}

		return $batchStates;
	}

	/**
	 * Builds the batch-push delta for points whose stats write is already committed.
	 *
	 * The technical flag and the track points come out of the write walk, so the common batch - movement
	 * points only - costs no query at all. Only a batch that really produced a marker (an ENTER, or a
	 * LEAVE that closed a stay) pays the day reread, the same rule the single add path follows for a
	 * technical point, and the counter comes out of that reread.
	 *
	 * @param array{userId: int, userTimezone: int, localDate: ?Date, markerCheckInId: int, points: array<int, array{lat: float, lon: float, time: int}>} $batchState
	 * @return array{userId: int, cnt?: int, lastMarker: ?array, isTechnical: bool, points: array<int, array{lat: float, lon: float, time: int}>, localDate: ?string}
	 */
	public function buildBatchDelta(array $batchState): array
	{
		$markerCheckInId = (int)$batchState['markerCheckInId'];
		$isTechnical = $markerCheckInId === 0;
		$day = $isTechnical ? null : $this->readDay($batchState, $markerCheckInId);

		return [
			'userId' => $batchState['userId'],
			...self::dayCountKey($day),
			'lastMarker' => $day['lastMarker'] ?? null,
			'isTechnical' => $isTechnical,
			'points' => $batchState['points'],
			// The queue of auto points lives on the device and survives an offline night, so a batch sent
			// in the morning can still count yesterday. The delta states the local day it belongs to - the
			// same date the daily stats are keyed by - and the client applies it only to the day it shows.
			'localDate' => $batchState['localDate'] instanceof Date
				? $batchState['localDate']->format('Y-m-d')
				: null,
		];
	}

	/**
	 * The count of the day as a key the push either carries or leaves out entirely.
	 *
	 * An absent key and an empty one are not the same thing on the wire. A client that meets no cnt keeps
	 * the number it already has, but Number(null) is 0 in JavaScript, so a cnt sent empty reads as a day
	 * holding nothing and blanks the badge of the employee on every movement point of his track. A push
	 * with nothing to say about the count therefore says nothing at all.
	 *
	 * @param ?array{lastMarker: ?array, cnt: int} $day
	 * @return array{cnt?: int}
	 */
	private static function dayCountKey(?array $day): array
	{
		return $day === null ? [] : ['cnt' => $day['cnt']];
	}

	/**
	 * The day reread both delta paths share: the single add and the batch pay it on the same terms, and
	 * only for a point that really produced a marker.
	 *
	 * The markers it builds answer both halves of the push at once - the card that has just appeared and
	 * how many visits the day now holds. The counter is not derived from the stored CNT beside them: a
	 * push feeds the very number the read path serves, and that number is the visits
	 * ({@see CheckInVisitCounter}), which a per-point delta cannot see the merges of.
	 *
	 * @param array{userId: int, userTimezone: int, localDate: ?Date} $state
	 * @return ?array{lastMarker: ?array, cnt: int}
	 */
	private function readDay(array $state, int $markerCheckInId): ?array
	{
		if (!($state['localDate'] instanceof Date))
		{
			return null;
		}

		$rows = $this->dayRowsReader->readMarkerRowsByUsers([$state['userId']], $state['localDate'])[$state['userId']]
			?? [];
		$markers = $this->aggregator->buildMarkers(AggregatorEventMapper::fromRows($rows));

		return [
			'lastMarker' => $this->buildLastMarker(
				$rows,
				$markers,
				$markerCheckInId,
				$state['userId'],
				$state['userTimezone'],
			),
			'cnt' => $this->visitCounter->countMarkers($markers),
		];
	}

	/**
	 * Whether a point becomes a marker of {@see CheckInMarkerAggregator}, read off the HAS_OPEN_ENTER
	 * the day carries before the point is applied. This is the whole technical/marker decision of both
	 * write paths: neither of them reads the day to take it.
	 */
	private function producesMarker(int $entityType, string $description, bool $hasOpenEnter): bool
	{
		if ($entityType === CheckInType::MANUAL->value)
		{
			return true;
		}

		if ($description === self::DESCRIPTION_ENTER)
		{
			return true;
		}

		if ($description === self::DESCRIPTION_LEAVE)
		{
			return $hasOpenEnter;
		}

		return false;
	}

	/**
	 * @param array<int, array{id: int, timestamp: int, latitude?: ?float, longitude?: ?float}> $groupPoints
	 * @return array<int, array{lat: float, lon: float, time: int}>
	 */
	private function extractDeltaPoints(array $groupPoints): array
	{
		$deltaPoints = [];
		foreach ($groupPoints as $point)
		{
			if (!isset($point['latitude'], $point['longitude']))
			{
				continue;
			}

			$deltaPoints[] = [
				'lat' => (float)$point['latitude'],
				'lon' => (float)$point['longitude'],
				'time' => (int)($point['timestamp'] ?? 0),
			];
		}

		return $deltaPoints;
	}

	/**
	 * Shifts the stored CNT by one check-in without rereading the day, and carries HAS_OPEN_ENTER with it.
	 *
	 * CNT counts the RECORDS of the day - manual check-ins and closed stays - and nothing reads it as a
	 * number for a screen: what every client shows is the visits ({@see CheckInVisitCounter}), and the two
	 * part company wherever a manual check-in stands on the stay the tracker recorded at the same place.
	 * A per-point delta cannot see that merge - it needs the whole day and the distances inside it - which
	 * is exactly why the counter of the screens is not kept here.
	 *
	 * HAS_OPEN_ENTER is the half that is read: both write paths take the technical/marker decision off it
	 * ({@see producesMarker()}) without going to the day, and that is what keeps a movement point O(1).
	 *
	 * @return array{0: int, 1: bool}
	 */
	private function calculateDelta(int $entityType, string $description, bool $hasOpenEnter): array
	{
		if ($entityType === CheckInType::MANUAL->value)
		{
			// A manual check-in is a marker of its own and never closes an open stay.
			return [1, $hasOpenEnter];
		}

		if ($description === self::DESCRIPTION_ENTER)
		{
			// A second ENTER closes the previous stay and opens its own.
			return [$hasOpenEnter ? 1 : 0, true];
		}

		if ($description === self::DESCRIPTION_LEAVE)
		{
			return [$hasOpenEnter ? 1 : 0, false];
		}

		return [0, $hasOpenEnter];
	}

	private function isNewerThanLast(int $lastCheckInId, int $localTimestamp): bool
	{
		if ($lastCheckInId <= 0)
		{
			return true;
		}

		$lastLocalTimestamp = $this->getCheckInLocalTimestamp($lastCheckInId);
		if ($lastLocalTimestamp === null)
		{
			return true;
		}

		return $localTimestamp > $lastLocalTimestamp;
	}

	private function getCheckInLocalTimestamp(int $checkInId): ?int
	{
		$row = CheckInTable::query()
			->setSelect(['DATE_CREATE', 'USER_TIMEZONE'])
			->where('ID', $checkInId)
			->setLimit(1)
			->exec()
			->fetch();

		if (!$row || !($row['DATE_CREATE'] instanceof DateTime))
		{
			return null;
		}

		return $row['DATE_CREATE']->getTimestamp() + (int)($row['USER_TIMEZONE'] ?? 0);
	}

	public function backFillForUser(int $userId, array $checkInRows, Date $localDate): void
	{
		if ($this->findStats($userId, $localDate) !== null)
		{
			return;
		}

		$cnt = 0;
		$hasOpenEnter = false;
		$lastCheckInId = 0;

		foreach ($checkInRows as $row)
		{
			$entityType = (int)($row['ENTITY_TYPE'] ?? 0);
			$description = (string)($row['DESCRIPTION'] ?? '');
			[$cntDelta, $newHasOpenEnter] = $this->calculateDelta($entityType, $description, $hasOpenEnter);
			$cnt += $cntDelta;

			$isClosingLeave =
				$entityType !== CheckInType::MANUAL->value
				&& $description === self::DESCRIPTION_LEAVE
				&& $hasOpenEnter;

			// Lazy backfill runs over historical rows, where a point from the future is the most likely
			// to be met, so the marker is protected here as well as on the write paths.
			if (
				!$isClosingLeave
				&& PointAcceptanceService::isLastCheckInCandidate(
					$entityType,
					$this->extractTimestamp($row['DATE_CREATE'] ?? null),
				)
			)
			{
				$lastCheckInId = (int)($row['ID'] ?? 0);
			}

			$hasOpenEnter = $newHasOpenEnter;
		}

		CheckInDailyStatsTable::add([
			'USER_ID' => $userId,
			'LOCAL_DATE' => $localDate,
			'CNT' => $cnt,
			'HAS_OPEN_ENTER' => $hasOpenEnter ? 1 : 0,
			'LAST_CHECK_IN_ID' => $lastCheckInId,
		]);
	}

	private function findStats(int $userId, Date $localDate): ?array
	{
		$row = CheckInDailyStatsTable::query()
			->setSelect(['ID', 'CNT', 'HAS_OPEN_ENTER', 'LAST_CHECK_IN_ID'])
			->where('USER_ID', $userId)
			->where('LOCAL_DATE', $localDate)
			->setLimit(1)
			->exec()
			->fetch();

		return $row ?: null;
	}

	private function createStats(int $userId, Date $localDate): array
	{
		$result = CheckInDailyStatsTable::add([
			'USER_ID' => $userId,
			'LOCAL_DATE' => $localDate,
			'CNT' => 0,
			'HAS_OPEN_ENTER' => 0,
			'LAST_CHECK_IN_ID' => 0,
		]);

		// On a unique-index race (USER_ID + LOCAL_DATE) add() returns a failed Result with a null id.
		// Fail in a controlled way instead of feeding null into update(): the save paths catch every
		// throwable and roll the whole transaction back into a Result-error.
		if (!$result->isSuccess())
		{
			throw new SystemException('Failed to create daily stats row');
		}

		return [
			'ID' => $result->getId(),
			'CNT' => 0,
			'HAS_OPEN_ENTER' => 0,
		];
	}

	private function getLocalDate(int $timestamp, int $userTimezone): Date
	{
		$localDateString = date('Y-m-d', $timestamp + $userTimezone);

		return new Date($localDateString, 'Y-m-d');
	}

	private function extractTimestamp(mixed $value): int
	{
		if ($value instanceof DateTime)
		{
			return $value->getTimestamp();
		}

		if (is_int($value))
		{
			return $value;
		}

		return 0;
	}
}
