<?php

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\StaffTrack\Feature;
use Bitrix\StaffTrack\Internal\Integration\Disk\FileResolver;
use Bitrix\StaffTrack\Internal\Integration\HumanResources\DepartmentProvider as HumanResourcesDepartmentProvider;
use Bitrix\StaffTrack\Internal\Integration\Pull;
use Bitrix\StaffTrack\Internal\Integration\Timeman\WorkDayService;
use Bitrix\StaffTrack\Internal\Model\CheckInDailyStatsTable;
use Bitrix\StaffTrack\Internal\Repository\CheckInRepository;
use Bitrix\StaffTrack\Internal\Service\CheckIn\AggregatorEventMapper;
use Bitrix\StaffTrack\Internal\Service\CheckIn\CheckInDayRowsReader;
use Bitrix\StaffTrack\Internal\Service\CheckIn\CheckInVisitCounter;
use Bitrix\StaffTrack\Public\Services\CheckInMarkerAggregator;
use Bitrix\StaffTrack\Public\Services\DailyStatsUpdater;
use Bitrix\StaffTrack\Public\Services\FirstManualCheckInCache;
use Bitrix\StaffTrack\Service\CheckIn\CheckInGeoCleanupService;
use Bitrix\StaffTrack\Public\Services\MapDataCache;

class CheckInProvider
{
	private const DAY_END_OFFSET = 86399;
	private const MAX_TIMEZONE_OFFSET = 50400;
	// Mirror of the tracker settings, moving either moves the gap threshold below: THRESHOLDS.TIME_CEILING_SEC
	// in stafftrackmobile/install/mobileapp/stafftrackmobile/extensions/stafftrack/check-in-v3/auto-check-in/
	// track-simplifier/extension.js and POLL_MOVING_MS in .../auto-check-in/geo-state-machine/extension.js.
	private const TRACKER_TIME_CEILING_SEC = 60;
	private const TRACKER_POLL_MOVING_SEC = 30;
	// A run released by the time ceiling lands exactly on it, so without slack a single missed poll would
	// dash a healthy recording. Two polls keep the line solid, real loss of coverage (minutes) still breaks it.
	private const GAP_MISSED_POLLS_ALLOWED = 2;
	private const GAP_THRESHOLD_SEC = self::TRACKER_TIME_CEILING_SEC
		+ (self::GAP_MISSED_POLLS_ALLOWED * self::TRACKER_POLL_MOVING_SEC);

	private CheckInRepository $repository;
	private CheckInDayRowsReader $dayRowsReader;
	private CheckInVisitCounter $visitCounter;

	public function __construct()
	{
		$this->repository = new CheckInRepository();
		$this->dayRowsReader = new CheckInDayRowsReader($this->repository);
		$this->visitCounter = new CheckInVisitCounter($this->dayRowsReader);
	}

	private function subscribeToPush(int $userId): void
	{
		Pull\PushSender::subscribeToTag(Pull\Tag::getUserTag($userId));
	}

	/**
	 * @return array{checkIns: array<int, array>, stats: array<int, int>, manualStats: array<int, int>, checkInRolloutEnabled: array<int, bool>, subscribedUserIds: int[]}
	 */
	public function getLatestUsersCheckInByDepartment(
		int $timezoneOffset = 0,
		int $timestamp = 0,
		array $entities = [],
	): array
	{
		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId <= 0)
		{
			return [
				'checkIns' => [],
				'stats' => [],
				'manualStats' => [],
				'checkInRolloutEnabled' => [],
				'subscribedUserIds' => [],
			];
		}

		if (!empty($entities))
		{
			$userIds = HumanResourcesDepartmentProvider::resolveDepartmentSelectorEntitiesForUser(
				$entities,
				$currentUserId,
			);

			$tags = array_map(
				static fn($userId) => Pull\Tag::getUserTag((int)$userId),
				$userIds,
			);
			Pull\PushSender::subscribeToTags($tags);
		}
		else
		{
			$this->subscribeToPush($currentUserId);

			$userIds = [
				$currentUserId,
				...DepartmentProvider::getSubordinateIds($currentUserId),
			];
		}

		$userIds = array_values(array_unique(array_map('intval', $userIds)));

		$requestedDate = $this->getRequestedDate($timestamp > 0 ? $timestamp : null, $timezoneOffset);

		if ($this->isPastLocalDate($requestedDate, $timezoneOffset))
		{
			$cached = MapDataCache::get($currentUserId, $requestedDate, $userIds);
			if ($cached !== null)
			{
				return [...$cached, 'subscribedUserIds' => $userIds];
			}

			$result = $this->collectLatestCheckInsWithStats($userIds, $timezoneOffset, $timestamp);
			$result['checkInRolloutEnabled'] = Feature::filterCheckInRolloutEnabled($userIds);
			$result['subscribedUserIds'] = $userIds;
			MapDataCache::set($currentUserId, $requestedDate, $userIds, $result);

			return $result;
		}

		$result = $this->collectLatestCheckInsWithStats($userIds, $timezoneOffset, $timestamp);
		$result['checkInRolloutEnabled'] = Feature::filterCheckInRolloutEnabled($userIds);
		$result['subscribedUserIds'] = $userIds;

		return $result;
	}

	private function isPastLocalDate(string $requestedDate, int $timezoneOffset): bool
	{
		$todayLocalDate = date('Y-m-d', time() + $timezoneOffset);

		return $requestedDate < $todayLocalDate;
	}

	/**
	 * @param int $userId
	 * @param int $timestamp
	 * @param int $timezoneOffset
	 * @param bool $withFlatList whether the answer carries the flat point list read by the v2 client
	 * @return array{route?: array<int, array>, track: array<int, array{id: int, lat: float, lon: float, time: int, gapBefore: bool, address: string, gapBeforeSec?: int, pausedBeforeSec?: int}>, markers: array<int, array>, isDayClosed: bool, stats?: array<int, int>, checkInRolloutEnabled: bool, serverNow: int, isDayLive: bool, dayStartTimestamp: ?int, dayCloseTimestamp: ?int, headGapSec: int, headPausedSec: int, tailPausedSec: int, tailPauseOpen: bool, durationsSuppressed: bool}
	 */
	public function getEmployeeRoute(
		int $userId,
		int $timestamp,
		int $timezoneOffset,
		bool $withFlatList = true
	): array
	{
		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId <= 0)
		{
			return [
				'route' => [],
				'track' => [],
				'markers' => [],
				'isDayClosed' => false,
				'checkInRolloutEnabled' => false,
			];
		}

		$this->subscribeToPush($currentUserId);
		$requestedDate = $this->getRequestedDate($timestamp, $timezoneOffset);
		[$dateStart, $dateEnd] = $this->getWideDayBoundaries($requestedDate);

		$dayRows = $this->filterDayRows(
			$this->dayRowsReader->readWindow($userId, $dateStart, $dateEnd),
			$requestedDate,
		);

		$dayResult = $this->buildDayResult($dayRows, $timezoneOffset, $currentUserId, $userId, $timestamp);

		// The day is already built above, so the badge is counted off the very markers this answer carries
		// instead of being read a second time: the number on the avatar and the rows of the timeline are
		// then one answer and cannot disagree. The daily-stats row is still raised - it is where a day
		// with no row of its own gets one ({@see getDailyStatsByUsers()}) - but no longer counted from.
		$this->getDailyStatsByUsers([$userId], $timezoneOffset, $timestamp);
		$stats = [$userId => $this->visitCounter->countMarkers($dayResult['rawMarkers'])];

		$result = array_merge(
			[
				'track' => $dayResult['track'],
				'markers' => $dayResult['markers'],
				'isDayClosed' => $dayResult['isDayClosed'],
				'stats' => $stats,
				'checkInRolloutEnabled' => Feature::isCheckInRolloutEnabled($userId),
			],
			$dayResult['timing'],
		);

		// the flat list is read by the v2 client, so it is served to everyone who did not refuse it: the
		// client generation is decided by the bundle on the device, which can lag behind the rollout flag
		// of its viewer, and a missing key draws an empty day with no error
		if ($withFlatList)
		{
			$result['route'] = self::dropCheckInsWithoutGeo(
				$this->normalizeCheckIns($dayRows, $timezoneOffset, $currentUserId),
			);
		}

		return $result;
	}

	/**
	 * @param int $userId
	 * @param int $timestamp
	 * @param int $timezoneOffset
	 * @param bool $withFlatList whether the answer carries the flat point list read by the v2 client
	 * @return array{checkIns?: array<int, array>, track?: array<int, array{id: int, lat: float, lon: float, time: int, gapBefore: bool, address: string, gapBeforeSec?: int, pausedBeforeSec?: int}>, markers?: array<int, array>, isDayClosed?: bool, dayCloseTimestamp?: ?int, checkInRolloutEnabled: bool, serverNow?: int, isDayLive?: bool, dayStartTimestamp?: ?int, headGapSec?: int, headPausedSec?: int, tailPausedSec?: int, tailPauseOpen?: bool, durationsSuppressed?: bool}
	 */
	public function getUserCheckInByDate(
		int $userId,
		int $timestamp,
		int $timezoneOffset = 0,
		bool $withFlatList = true
	): array
	{
		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId <= 0)
		{
			return ['checkInRolloutEnabled' => false];
		}

		$this->subscribeToPush($currentUserId);
		$requestedDate = $this->getRequestedDate($timestamp, $timezoneOffset);
		[$dateStart, $dateEnd] = $this->getWideDayBoundaries($requestedDate);

		$dayRows = $this->filterDayRows(
			$this->dayRowsReader->readWindowWithAttachments($userId, $dateStart, $dateEnd),
			$requestedDate,
		);

		$dayResult = $this->buildDayResult($dayRows, $timezoneOffset, $currentUserId, $userId, $timestamp);

		$result = array_merge(
			[
				'track' => $dayResult['track'],
				'markers' => $dayResult['markers'],
				'isDayClosed' => $dayResult['isDayClosed'],
				'checkInRolloutEnabled' => Feature::isCheckInRolloutEnabled($userId),
			],
			$dayResult['timing'],
		);

		// the same flat list under the other name of the two read paths, served on the same terms:
		// to everyone who did not refuse it, because the bundle on the device decides which
		// generation asks and it can lag behind the rollout flag of its viewer
		if ($withFlatList)
		{
			$result['checkIns'] = self::dropCheckInsWithoutGeo(
				$this->normalizeCheckIns($dayRows, $timezoneOffset, $currentUserId),
			);
		}

		return $result;
	}

	/**
	 * @param int $userId
	 * @param int $timestamp
	 * @param int|null $timezoneOffset
	 * @return int
	 */
	public function getManualCheckInCountByDate(int $userId, int $timestamp, ?int $timezoneOffset = null): int
	{
		$timezoneOffset ??= \CTimeZone::GetOffset();

		$requestedDate = $this->getRequestedDate($timestamp, $timezoneOffset);
		[$dateStart, $dateEnd] = $this->getWideDayBoundaries($requestedDate);

		$rows = $this->repository->getUserManualCheckInDatesByPeriod($userId, $dateStart, $dateEnd);

		$count = 0;
		foreach ($rows as $row)
		{
			if ($this->isCheckInOnLocalDate($row, $requestedDate))
			{
				$count++;
			}
		}

		return $count;
	}

	public function isFirstManualCheckInToday(int $userId, ?int $timezoneOffset = null): bool
	{
		$timezoneOffset ??= \CTimeZone::GetOffset();
		$localDate = $this->getRequestedDate(time(), $timezoneOffset);

		$cached = FirstManualCheckInCache::get($userId, $localDate);
		if ($cached !== null)
		{
			return $cached;
		}

		$isFirst = $this->getManualCheckInCountByDate($userId, time(), $timezoneOffset) === 0;
		FirstManualCheckInCache::set($userId, $localDate, $isFirst);

		return $isFirst;
	}

	/**
	 * @param int $userId
	 * @param int $timestamp
	 * @param int|null $timezoneOffset
	 * @return array<int, array>
	 */
	public function getCurrentUserManualCheckInsByDate(int $userId, int $timestamp, ?int $timezoneOffset = null): array
	{
		$timezoneOffset ??= \CTimeZone::GetOffset();

		$requestedDate = $this->getRequestedDate($timestamp, $timezoneOffset);
		[$dateStart, $dateEnd] = $this->getWideDayBoundaries($requestedDate);

		$dayRows = $this->filterDayRows(
			$this->dayRowsReader->readManualWindow($userId, $dateStart, $dateEnd),
			$requestedDate,
		);

		return $this->normalizeCheckIns($dayRows, $timezoneOffset, $userId);
	}

	/**
	 * @param int[] $userIds
	 * @return array{checkIns: array<int, array>, stats: array<int, int>, manualStats: array<int, int>}
	 */
	private function collectLatestCheckInsWithStats(array $userIds, int $timezoneOffset, int $timestamp): array
	{
		$userIds = array_values(array_unique(array_map('intval', $userIds)));
		if (empty($userIds))
		{
			return ['checkIns' => [], 'stats' => [], 'manualStats' => []];
		}

		$statsByUser = $this->getDailyStatsByUsers($userIds, $timezoneOffset, $timestamp);
		if (empty($statsByUser))
		{
			return ['checkIns' => [], 'stats' => [], 'manualStats' => []];
		}

		$lastCheckInIds = $this->extractLastCheckInIds($statsByUser);
		$checkInsById = $this->resolveLastCheckIns($lastCheckInIds, $userIds, $timezoneOffset, $timestamp);

		$checkIns = [];
		$badgeUserIds = [];
		foreach ($statsByUser as $statRow)
		{
			$checkIn = $checkInsById[(int)$statRow['LAST_CHECK_IN_ID']] ?? null;
			if ($checkIn === null || !CheckInNormalizer::hasGeo($checkIn))
			{
				continue;
			}

			$badgeUserIds[] = (int)$statRow['USER_ID'];
			$checkIns[] = CheckInNormalizer::normalize($checkIn, $timezoneOffset);
		}

		$requestedDate = $this->getRequestedDate($timestamp > 0 ? $timestamp : null, $timezoneOffset);
		// The badge of every avatar on the team map, counted by the aggregator that draws the day itself.
		// The stored CNT beside it counts records - a manual check-in taken where the tracker recorded a
		// stay is two of those and one visit - and the employee whose card the map opens would then meet
		// a smaller number on the very next screen. Only the employees the map shows are counted: the team
		// is not capped, and a day read for nobody costs as much as a shown one.
		$visitsByUser = $this->visitCounter->countByUsers(
			$badgeUserIds,
			new \Bitrix\Main\Type\Date($requestedDate, 'Y-m-d'),
		);

		$stats = [];
		foreach ($badgeUserIds as $userId)
		{
			$stats[$userId] = $visitsByUser[$userId] ?? 0;
		}

		$manualStats = $this->getManualStatsByUsers(array_keys($stats), $requestedDate);

		return ['checkIns' => $checkIns, 'stats' => $stats, 'manualStats' => $manualStats];
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, int>
	 */
	private function getManualStatsByUsers(array $userIds, string $requestedDate): array
	{
		if (empty($userIds))
		{
			return [];
		}

		[$dateStart, $dateEnd] = $this->getWideDayBoundaries($requestedDate);
		$rows = $this->repository->getAllUsersManualCheckInByPeriod($userIds, $dateStart, $dateEnd);

		$manualStats = [];
		foreach ($rows as $row)
		{
			if (!$this->isCheckInOnLocalDate($row, $requestedDate))
			{
				continue;
			}

			$userId = (int)$row['USER_ID'];
			$manualStats[$userId] = ($manualStats[$userId] ?? 0) + 1;
		}

		return $manualStats;
	}

	/**
	 * @param array<int, array> $statsByUser
	 * @return int[]
	 */
	private function extractLastCheckInIds(array $statsByUser): array
	{
		$ids = [];
		foreach ($statsByUser as $stats)
		{
			$id = (int)$stats['LAST_CHECK_IN_ID'];
			if ($id > 0)
			{
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * @param int[] $lastCheckInIds
	 * @param int[] $userIds
	 * @return array<int, array>
	 */
	private function resolveLastCheckIns(array $lastCheckInIds, array $userIds, int $timezoneOffset, int $timestamp): array
	{
		if (empty($lastCheckInIds))
		{
			return [];
		}

		$checkInRows = $this->repository->getCheckInsByIds($lastCheckInIds);
		$checkInsById = array_column($checkInRows, null, 'ID');

		$unresolvedIds = array_diff($lastCheckInIds, array_map('intval', array_keys($checkInsById)));
		if (empty($unresolvedIds))
		{
			return $checkInsById;
		}

		foreach ($this->findAggregatedCheckInsByIds($unresolvedIds, $userIds, $timezoneOffset, $timestamp) as $id => $point)
		{
			$checkInsById[$id] = $point;
		}

		return $checkInsById;
	}

	/**
	 * @param int[] $unresolvedIds
	 * @param int[] $userIds
	 * @return array<int, array>
	 */
	private function findAggregatedCheckInsByIds(array $unresolvedIds, array $userIds, int $timezoneOffset, int $timestamp): array
	{
		$requestedDate = $this->getRequestedDate($timestamp > 0 ? $timestamp : null, $timezoneOffset);
		[$dateStart, $dateEnd] = $this->getWideDayBoundaries($requestedDate);

		$aggregatedPoints = $this->repository->getAllUsersAggregatedPointsByPeriod($userIds, $dateStart, $dateEnd);
		$unresolvedLookup = array_flip($unresolvedIds);

		$found = [];
		foreach ($aggregatedPoints as $point)
		{
			$id = (int)($point['ID'] ?? 0);
			if (isset($unresolvedLookup[$id]))
			{
				$found[$id] = $point;
			}
		}

		return $found;
	}

	/**
	 * @param array $userIds
	 * @param int $timezoneOffset
	 * @param int $timestamp
	 * @return array<int, array> userId => stats row
	 */
	private function getDailyStatsByUsers(array $userIds, int $timezoneOffset, int $timestamp = 0): array
	{
		$requestedDate = $this->getRequestedDate($timestamp > 0 ? $timestamp : null, $timezoneOffset);
		$localDate = new \Bitrix\Main\Type\Date($requestedDate, 'Y-m-d');

		$statsRows = $this->repository->getDailyStatsByUsers($userIds, $localDate);

		$usersWithStats = array_map(static fn($row) => (int)$row['USER_ID'], $statsRows);
		$usersWithoutStats = array_diff($userIds, $usersWithStats);

		if (!empty($usersWithoutStats))
		{
			$this->backFillMissingStats($usersWithoutStats, $requestedDate, $localDate);
			$statsRows = $this->repository->getDailyStatsByUsers($userIds, $localDate);
		}

		$statsByUser = [];
		foreach ($statsRows as $row)
		{
			$statsByUser[(int)$row['USER_ID']] = $row;
		}

		return $statsByUser;
	}

	/**
	 * @param string $requestedDate 'Y-m-d'
	 * @return array{0: DateTime, 1: DateTime}
	 */
	private function getWideDayBoundaries(string $requestedDate): array
	{
		$midnightUtc = strtotime($requestedDate . ' 00:00:00 UTC');
		$endOfDayUtc = $midnightUtc + self::DAY_END_OFFSET;

		return [
			DateTime::createFromTimestamp($midnightUtc - self::MAX_TIMEZONE_OFFSET),
			DateTime::createFromTimestamp($endOfDayUtc + self::MAX_TIMEZONE_OFFSET),
		];
	}

	/**
	 * @param int|null $timestamp
	 * @param int $requesterTimezoneOffset
	 * @return string 'Y-m-d'
	 */
	private function getRequestedDate(?int $timestamp, int $requesterTimezoneOffset): string
	{
		if ($timestamp === null || $timestamp <= 0)
		{
			$timestamp = time();
		}

		return date('Y-m-d', $timestamp + $requesterTimezoneOffset);
	}

	/**
	 * @param array<int, array> $dayRows
	 * @return array<int, array>
	 */
	private function normalizeCheckIns(array $dayRows, int $timezoneOffset, int $currentUserId): array
	{
		$filesMap = (new FileResolver())->resolveMany(
			self::collectCheckInFiles($dayRows),
			$currentUserId,
		);

		$result = [];
		foreach ($dayRows as $item)
		{
			$checkInId = (int)($item['ID'] ?? 0);
			$result[] = CheckInNormalizer::normalize($item, $timezoneOffset, $filesMap[$checkInId] ?? []);
		}

		return $result;
	}

	/**
	 * The window is read wide enough to hold the day of any offset, so what comes back is cut down to the
	 * day that was asked about. The order the reader put the rows in is the order they keep.
	 *
	 * @param array<int, array> $rows
	 * @return array<int, array>
	 */
	private function filterDayRows(array $rows, string $requestedDate): array
	{
		$dayRows = [];
		foreach ($rows as $row)
		{
			if ($this->isCheckInOnLocalDate($row, $requestedDate))
			{
				$dayRows[] = $row;
			}
		}

		return $dayRows;
	}

	/**
	 * @return array{track: array<int, array{lat: float, lon: float, time: int, gapBefore: bool}>, markers: array<int, array>, rawMarkers: array<int, array>, isDayClosed: bool, timing: array{serverNow: int, isDayLive: bool, dayStartTimestamp: ?int, dayCloseTimestamp: ?int, headGapSec: int, headPausedSec: int, tailPausedSec: int, tailPauseOpen: bool, durationsSuppressed: bool}}
	 */
	private function buildDayResult(
		array $dayRows,
		int $timezoneOffset,
		int $currentUserId,
		int $userId,
		int $timestamp
	): array
	{
		$serverNow = time();
		// The day being asked about, read the way the rows of this answer were already collected: a
		// timestamp of nothing is how a client says "today" (getRequestedDate), and taken raw here it would
		// put the work day of 1970 under the track of today - a day answered as one nobody ever worked.
		$timestamp = $timestamp > 0 ? $timestamp : $serverNow;

		// One day and one clock: the work day is windowed by the very moment and offset the points of this
		// answer were already collected under, and not by the clock of whoever is looking at them.
		[$dayAnchorTimestamp, $dayAnchorOffset] = self::dayReadingAnchor($dayRows, $timestamp, $timezoneOffset, $userId);
		// And the same window says how old the day is: the cleanup meets the points where they begin, so
		// that is the edge the retention is measured at - see areDurationsSuppressed().
		[$dayWindowStart, $dayWindowEnd] = WorkDayService::getDayWindow($dayAnchorTimestamp, $dayAnchorOffset);

		$withDurations = !self::areDurationsSuppressed(
			$this->isGeoRetentionLimited(),
			$dayWindowStart,
			$this->geoRetentionCutoff($serverNow),
		);

		// The bounds, the flag, the moment of the closing and the breaks come from one reading of the day.
		// The bounds are work time tracking's to withhold, while the flag and the moment are answered to
		// everyone: the flag withheld would grow a live row on a day that is already over, and the moment is
		// what the day is READ by below rather than a time this answer carries. Breaks sign the silence, and
		// only durations are signed with them.
		//
		// Both halves are asked under one and the same condition, because both are read for the durations
		// alone: the bounds are the edges of the track, so a day with no points has nothing to draw them
		// around, and a day whose durations are withheld has nothing to measure between them - its client
		// leaves before it ever reads them. Asked all the same, they would cost the resolve of an entire org
		// structure for an answer nobody unpacks, and nothing downstream would ever show it.
		$withDayReading = $withDurations && $dayRows !== [];

		[
			'start' => $dayStartTimestamp,
			'end' => $dayCloseTimestamp,
			'closed' => $isDayClosed,
			'closedAtUnrestricted' => $dayClosedAtUnrestricted,
			'pauses' => $pauses,
		] = WorkDayService::getDay(
			$userId,
			$dayAnchorTimestamp,
			$dayAnchorOffset,
			withBounds: $withDayReading,
			withPauses: $withDayReading,
		);

		// A closed record can carry a stop written into the future - stopping a paused day records the
		// planned end of the pause, not the moment of the stop. A tail or a stay measured to such a moment
		// would name minutes nobody has lived yet, so both closing moments are held to the present.
		$dayCloseTimestamp = $dayCloseTimestamp === null ? null : min($dayCloseTimestamp, $serverNow);
		$dayClosedAtUnrestricted = $dayClosedAtUnrestricted === null
			? null
			: min($dayClosedAtUnrestricted, $serverNow);

		$rawRowsById = [];
		foreach ($dayRows as $row)
		{
			$rawRowsById[(int)($row['ID'] ?? 0)] = $row;
		}

		$events = AggregatorEventMapper::fromRows($dayRows);
		// The reading of the day takes the closing itself, not the one the reader may be told: a stay left
		// open at the end of a finished day ends where the day did, and read without that moment it would go
		// on growing towards now for every reader who may not have it. What leaves here is the marker, whose
		// length was always the same for everybody the timeline is open to; the moment stays withheld in the
		// `timing` below.
		//
		// Withheld there and arithmetic here: the last stay of a closed day ends exactly at the closing, so
		// `time + duration` of that marker gives the moment back to any reader of the ribbon. That is the
		// price paid knowingly - the lengths of the stays of a finished day are what every reader admitted to
		// the timeline has always seen, master included - and what the right still guards is the field of
		// `timing` that states the moment outright.
		$markers = (new CheckInMarkerAggregator())->buildMarkers($events, $dayClosedAtUnrestricted);

		$leadingRows = [];
		foreach ($markers as $marker)
		{
			if ($marker['kind'] === CheckInMarkerAggregator::KIND_STAY)
			{
				continue;
			}

			$leadingId = (int)($marker['event']['id'] ?? 0);
			if (isset($rawRowsById[$leadingId]))
			{
				$leadingRows[] = $rawRowsById[$leadingId];
			}
		}

		$filesMap = (new FileResolver())->resolveMany(
			self::collectCheckInFiles($leadingRows),
			$currentUserId,
		);

		$normalizedMarkers = [];
		foreach ($markers as $marker)
		{
			$leadingId = (int)($marker['event']['id'] ?? 0);
			$normalizedMarkers[] = CheckInMarkerNormalizer::normalize(
				$marker,
				$rawRowsById,
				$filesMap[$leadingId] ?? [],
				$timezoneOffset,
			);
		}

		$track = self::buildTrack(
			$dayRows,
			self::collectStayIntervals($markers),
			$pauses,
			$serverNow,
			$withDurations,
		);

		return [
			'track' => $track,
			'markers' => $normalizedMarkers,
			// The markers as the aggregator answered them, for a caller that has to count the visits of
			// the day rather than draw them - counted off the same build, never off a second reading.
			'rawMarkers' => $markers,
			'isDayClosed' => $isDayClosed,
			'timing' => self::buildDayTiming(
				$track,
				$dayStartTimestamp,
				$dayCloseTimestamp,
				$pauses,
				$serverNow,
				$withDurations,
				// Whether the day of THIS answer is being lived through right now, told by the same window
				// the bounds were read from. The reader's calendar cannot answer that: the day is anchored
				// to the employee's own timezone, and a reader hours away would go on growing the tail of a
				// day the employee has already left.
				isDayLive: $serverNow >= $dayWindowStart && $serverNow < $dayWindowEnd,
			),
		];
	}

	/**
	 * The moment and the offset the work day of this answer is windowed by - the same pair the points of the
	 * day were selected under.
	 *
	 * A point belongs to the day when the clock it was written by puts it there: the date is named by the
	 * reader (getRequestedDate), but which points fall under that date is decided by the USER_TIMEZONE stamped
	 * on each of them, the offset of the employee himself (isCheckInOnLocalDate). Windowed by the reader
	 * instead, the work day would be another twenty four hours than the track drawn beside it - shifted by
	 * however far apart the two live - and its start, its breaks and both edges of the ribbon would be read
	 * off somebody else's day. So the window is anchored on a point of this day: its own moment and its own
	 * offset give back exactly the day it was taken from, whatever clock the reader keeps.
	 *
	 * The first point decides for the whole day - one offset per answer, and an employee who moved between
	 * time zones in the middle of it is answered by the one his day began under. The moment comes off that
	 * point, the offset off the first point that carries one: the stamp is nullable, and a day opening with
	 * a folded point that kept none would be windowed in the hours of the server instead of his own.
	 *
	 * A day of no points is read for nothing but whether it is over, and there is no point to take the pair
	 * from: such a day keeps the offset of the request, as it always did. Nothing of the day hangs on it -
	 * an empty day has no track to measure and no geodata to have lost.
	 *
	 * @param array<int, array> $dayRows
	 * @return array{0: int, 1: int}
	 */
	private static function dayReadingAnchor(
		array $dayRows,
		int $timestamp,
		int $timezoneOffset,
		int $userId
	): array
	{
		if ($dayRows === [])
		{
			return [$timestamp, $timezoneOffset];
		}

		$firstRow = $dayRows[array_key_first($dayRows)];
		$dateCreate = $firstRow['DATE_CREATE'] ?? null;

		return [
			$dateCreate instanceof DateTime ? $dateCreate->getTimestamp() : (int)$dateCreate,
			CheckInDayRowsReader::resolveTimezoneOffset($dayRows, $userId),
		];
	}

	/**
	 * The edges of the day are counted by the server: the first point of the track carries no gap by
	 * construction, and the pauses themselves never leave the server, so without a ready pair of
	 * numbers the client could not tell a break apart from missing recording at either end.
	 *
	 * Suppressed durations are said to be suppressed rather than served as zeros: the lower edge is the one
	 * length the client works out for itself, from the times of the day, and zeros alone would leave it
	 * measuring a silence this answer is deliberately withholding.
	 *
	 * @param array<int, array{time: int}> $track
	 * @param array<int, array{from: int, to: ?int}> $pauses
	 * @param bool $isDayLive whether the day of the answer is being lived through by its own window;
	 *        absent from older portals, and the client treats absence as "live" to keep their ribbon as it was
	 * @return array{serverNow: int, isDayLive: bool, dayStartTimestamp: ?int, dayCloseTimestamp: ?int, headGapSec: int, headPausedSec: int, tailPausedSec: int, tailPauseOpen: bool, durationsSuppressed: bool}
	 */
	private static function buildDayTiming(
		array $track,
		?int $dayStartTimestamp,
		?int $dayCloseTimestamp,
		array $pauses,
		int $serverNow,
		bool $withDurations,
		bool $isDayLive = true
	): array
	{
		$timing = [
			'serverNow' => $serverNow,
			'isDayLive' => $isDayLive,
			'dayStartTimestamp' => $dayStartTimestamp,
			'dayCloseTimestamp' => $dayCloseTimestamp,
			'headGapSec' => 0,
			'headPausedSec' => 0,
			'tailPausedSec' => 0,
			'tailPauseOpen' => false,
			'durationsSuppressed' => !$withDurations,
		];

		if (empty($track) || !$withDurations)
		{
			return $timing;
		}

		if ($dayStartTimestamp !== null)
		{
			$firstPointTime = (int)$track[array_key_first($track)]['time'];
			$timing['headGapSec'] = self::silenceSeconds($dayStartTimestamp, $firstPointTime, $serverNow);
			$timing['headPausedSec'] = self::pausedSecondsWithin(
				$dayStartTimestamp,
				$dayStartTimestamp + $timing['headGapSec'],
				$pauses,
				$serverNow,
			);
		}

		// the tail grows while the day is running, so its own length is left to the client; the server
		// answers for what it alone knows - how much of the tail so far a break accounts for
		$lastPointTime = (int)$track[array_key_last($track)]['time'];
		$tailEnd = $dayCloseTimestamp ?? $serverNow;
		$timing['tailPausedSec'] = self::pausedSecondsWithin($lastPointTime, $tailEnd, $pauses, $serverNow);
		$timing['tailPauseOpen'] = $dayCloseTimestamp === null && self::hasOpenPause($pauses);

		return $timing;
	}

	/**
	 * @param array<int, array{from: int, to: ?int}> $pauses
	 */
	private static function hasOpenPause(array $pauses): bool
	{
		foreach ($pauses as $pause)
		{
			if (($pause['to'] ?? null) === null)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Geodata older than the retention period is wiped by the cleanup, and the points left behind no
	 * longer describe the silence of that day: the day is served as before, but with no durations, so
	 * there is nothing for the client to sign a dash with.
	 *
	 * The day is weighed by where it begins and not by the moment the reader named it with. What the
	 * cleanup deletes by is the stamp on the point itself, plain server time; what makes a point part of
	 * this day is its local date, the USER_TIMEZONE of the employee stamped beside it. The two are as far
	 * apart as that offset - up to a day - and a moment taken from the wrong side of the line answers for
	 * a day the cleanup sees differently: a day still whole read as wiped, or a day already half gone read
	 * as intact and measured over the part of the track that survived.
	 *
	 * Suppressed as soon as the cleanup COULD have reached any part of the day: it takes everything stamped
	 * at or before the cutoff, so a day whose first second is already there has lost a piece of its track,
	 * and the silence measured over what is left never happened. Only a day lying wholly past the cutoff
	 * keeps its lengths.
	 *
	 * @param int $dayWindowStart the first moment of the local day, in server time
	 * @param int $retentionCutoff the stamp the cleanup deletes up to, inclusive
	 */
	private static function areDurationsSuppressed(
		bool $geoRetentionLimited,
		int $dayWindowStart,
		int $retentionCutoff
	): bool
	{
		if (!$geoRetentionLimited)
		{
			return false;
		}

		return $dayWindowStart <= $retentionCutoff;
	}

	/**
	 * The line the cleanup deletes up to, counted the way the cleanup counts it - the same retention and
	 * the same arithmetic as CheckInGeoCleanupService::getThreshold(), so the two never disagree about
	 * which side of it a day is on.
	 *
	 * The cleanup counts it from the moment its agent runs and this answer from the moment it is built, so
	 * the line here is never behind the one the geodata was actually wiped by. It leans the way the whole
	 * rule leans: a day the agent has not reached yet is answered as one it already has, rather than the
	 * other way round.
	 */
	protected function geoRetentionCutoff(int $serverNow): int
	{
		return (int)strtotime('-' . CheckInGeoCleanupService::RETENTION_MONTHS . ' months', $serverNow);
	}

	/**
	 * Whether the region of the portal has the cleanup thin the geodata out at all. It comes from the license
	 * of the portal, which no test can shape, so it stands apart from the rule that reads it.
	 */
	protected function isGeoRetentionLimited(): bool
	{
		return Feature::isGeoRetentionLimited();
	}

	/**
	 * Every point carries the id and the address of the check-in it stands on: the ends of the route are
	 * technical moving points absent from `markers`, so this is the only path by which the address queue
	 * reaches them and by which the resolved address (pull patch, keyed by check-in id) gets back.
	 *
	 * @param array<int, array> $rows
	 * @param array<int, array{from: int, to: int}> $stayIntervals
	 * @param array<int, array{from: int, to: ?int}> $pauses
	 * @return array<int, array{id: int, lat: float, lon: float, time: int, gapBefore: bool, address: string, gapBeforeSec?: int, pausedBeforeSec?: int}>
	 */
	private static function buildTrack(
		array $rows,
		array $stayIntervals = [],
		array $pauses = [],
		?int $serverNow = null,
		bool $withDurations = true
	): array
	{
		$serverNow ??= time();

		$track = [];
		$prev = null;
		foreach ($rows as $row)
		{
			$latitude = $row['LATITUDE'] ?? null;
			$longitude = $row['LONGITUDE'] ?? null;
			if ($latitude === null || $longitude === null)
			{
				continue;
			}

			$dateCreate = $row['DATE_CREATE'] ?? null;
			$timestamp = $dateCreate instanceof DateTime ? $dateCreate->getTimestamp() : (int)$dateCreate;
			$lat = (float)$latitude;
			$lon = (float)$longitude;

			$gapBefore = false;
			$gapSec = 0;
			if ($prev !== null)
			{
				$deltaSec = $timestamp - $prev['time'];
				$gapBefore = $deltaSec > self::GAP_THRESHOLD_SEC
					&& !self::isCoveredByStay($prev['time'], $timestamp, $stayIntervals);
				$gapSec = self::silenceSeconds($prev['time'], $timestamp, $serverNow);
			}

			$point = [
				'id' => (int)($row['ID'] ?? 0),
				'lat' => $lat,
				'lon' => $lon,
				'time' => $timestamp,
				'gapBefore' => $gapBefore,
				'address' => (string)($row['ADDRESS'] ?? ''),
			];

			// only a gap carries a duration: the answer already grows by every point of the day, and
			// silence the line draws as solid has nothing to sign
			if ($gapBefore && $gapSec > 0 && $withDurations)
			{
				$point['gapBeforeSec'] = $gapSec;
				$point['pausedBeforeSec'] = self::pausedSecondsWithin(
					$prev['time'],
					$prev['time'] + $gapSec,
					$pauses,
					$serverNow,
				);
			}

			$track[] = $point;
			$prev = $point;
		}

		return $track;
	}

	/**
	 * Silence measured by the server clock: a point stamped ahead of it, or not later than the point
	 * before it, would otherwise report a silence the day has never been silent for.
	 */
	private static function silenceSeconds(int $from, int $to, int $serverNow): int
	{
		return max(0, min($to, $serverNow) - $from);
	}

	/**
	 * Seconds of the interval the work day spent on a break. A pause that is still running covers
	 * everything up to the moment the answer is built and grows together with it.
	 *
	 * @param array<int, array{from: int, to: ?int}> $pauses
	 */
	private static function pausedSecondsWithin(int $from, int $to, array $pauses, int $serverNow): int
	{
		$paused = 0;
		foreach ($pauses as $pause)
		{
			$pauseFrom = (int)($pause['from'] ?? 0);
			$pauseTo = ($pause['to'] ?? null) === null ? $serverNow : (int)$pause['to'];

			$paused += max(0, min($to, $pauseTo) - max($from, $pauseFrom));
		}

		return $paused;
	}

	/**
	 * A stay accounts for the silence between its own ends: the tracker stops writing points there by
	 * design, so the line stays solid. Silence anywhere else is missing recording - a standing employee
	 * and an application that was not running look alike in the track, and only the stay tells them apart.
	 *
	 * @param array<int, array{from: int, to: int}> $stayIntervals
	 */
	private static function isCoveredByStay(int $from, int $to, array $stayIntervals): bool
	{
		foreach ($stayIntervals as $interval)
		{
			if ($interval['from'] <= $from && $to <= $interval['to'])
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, array{kind: string, timestamp: int, endTimestamp: int, duration: ?int, auto: ?array}> $markers
	 * @return array<int, array{from: int, to: int}>
	 */
	private static function collectStayIntervals(array $markers): array
	{
		$intervals = [];
		foreach ($markers as $marker)
		{
			$interval = self::stayIntervalOf($marker);
			if ($interval !== null)
			{
				$intervals[] = $interval;
			}
		}

		return $intervals;
	}

	/**
	 * A stay swallowed by a manual check-in keeps the silence of its own ends: the merge changed what the
	 * day shows, not what the tracker recorded. The rest of the merged span stays dashed - the merge holds
	 * two events together across silence far longer than a gap, so there the missing recording is real.
	 *
	 * @return ?array{from: int, to: int}
	 */
	private static function stayIntervalOf(array $marker): ?array
	{
		$kind = $marker['kind'] ?? '';

		if ($kind === CheckInMarkerAggregator::KIND_STAY)
		{
			$from = (int)($marker['timestamp'] ?? 0);

			return ['from' => $from, 'to' => max($from, (int)($marker['endTimestamp'] ?? $from))];
		}

		if ($kind === CheckInMarkerAggregator::KIND_MERGED && isset($marker['auto']['timestamp']))
		{
			$from = (int)$marker['auto']['timestamp'];

			return ['from' => $from, 'to' => $from + max(0, (int)($marker['duration'] ?? 0))];
		}

		return null;
	}

	/**
	 * Excludes check-ins without geodata (coordinates reset by the cleanup agent) from the map output.
	 * So that a cleaned check-in is not displayed as a point (0, 0).
	 *
	 * @param array<int, array> $checkIns
	 * @return array<int, array>
	 */
	private static function dropCheckInsWithoutGeo(array $checkIns): array
	{
		return array_values(array_filter(
			$checkIns,
			static fn(array $checkIn): bool => ($checkIn['latitude'] ?? null) !== null && ($checkIn['longitude'] ?? null) !== null,
		));
	}

	/**
	 * @return array<int, array{id: int, fileIds: array}>
	 */
	private static function collectCheckInFiles(array $checkIns): array
	{
		$result = [];
		foreach ($checkIns as $checkIn)
		{
			$fileIds = is_array($checkIn['FILE_IDS'] ?? null) ? $checkIn['FILE_IDS'] : [];
			if (!empty($fileIds))
			{
				$result[] = [
					'id' => (int)($checkIn['ID'] ?? 0),
					'fileIds' => $fileIds,
				];
			}
		}

		return $result;
	}

	private function backFillMissingStats(array $userIds, string $requestedDate, Main\Type\Date $localDate): void
	{
		[$dateStart, $dateEnd] = $this->getWideDayBoundaries($requestedDate);

		$allCheckInRows = $this->repository->getAllUsersCheckInByPeriod($userIds, $dateStart, $dateEnd);
		$allAggregatedPoints = $this->repository->getAllUsersAggregatedPointsByPeriod($userIds, $dateStart, $dateEnd);

		$checkInsByUser = [];
		foreach (array_merge($allCheckInRows, $allAggregatedPoints) as $row)
		{
			if ($this->isCheckInOnLocalDate($row, $requestedDate))
			{
				$checkInsByUser[(int)$row['USER_ID']][] = $row;
			}
		}

		$statsUpdater = new DailyStatsUpdater();

		foreach ($userIds as $userId)
		{
			$allCheckIns = $checkInsByUser[$userId] ?? [];
			if (empty($allCheckIns))
			{
				continue;
			}

			usort($allCheckIns, static function ($a, $b)
			{
				$tsA = ($a['DATE_CREATE'] instanceof DateTime ? $a['DATE_CREATE']->getTimestamp() : (int)$a['DATE_CREATE']) + (int)($a['USER_TIMEZONE'] ?? 0);
				$tsB = ($b['DATE_CREATE'] instanceof DateTime ? $b['DATE_CREATE']->getTimestamp() : (int)$b['DATE_CREATE']) + (int)($b['USER_TIMEZONE'] ?? 0);

				return $tsA <=> $tsB ?: ((int)($a['ID'] ?? 0) <=> (int)($b['ID'] ?? 0));
			});

			$statsUpdater->backFillForUser($userId, $allCheckIns, $localDate);
		}
	}

	private function isCheckInOnLocalDate(array $checkIn, string $requestedDate): bool
	{
		$dateCreate = $checkIn['DATE_CREATE'] ?? null;

		if ($dateCreate instanceof DateTime)
		{
			$timestamp = $dateCreate->getTimestamp();
		}
		elseif (is_int($dateCreate))
		{
			$timestamp = $dateCreate;
		}
		else
		{
			return false;
		}

		$userTimezone = (int)($checkIn['USER_TIMEZONE'] ?? 0);
		$localDate = date('Y-m-d', $timestamp + $userTimezone);

		return $localDate === $requestedDate;
	}
}
